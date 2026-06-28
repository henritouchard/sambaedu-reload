# Story 30.5: Validation prédictive à l'assignation

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **refnum** (administrateur de l'instance SE5),
I want **être averti explicitement, AU MOMENT où j'assigne un label à un parc ou où je rattache un poste à un parc labellisé, qu'une collision INSOLUBLE en découlerait — deux items amont VERROUILLÉS (`locked`) imposant des valeurs CONTRADICTOIRES sur la MÊME propriété (`exclusiveKey()`) d'un même poste — l'assignation étant alors REFUSÉE (aucune écriture)**, en RÉUTILISANT le socle de résolution amont livré en 30.4 (`UpstreamContractSource` : items `label` groupés par `target_label`, maille `Upstream` pour `locked`) pour **prédire** le conflit, **sans toucher** `StateCompiler` / `specificity()` / `StateMaille` / `AgentServiceProvider`,
so that **la contradiction soit interceptée AVANT d'atteindre le poste — pas de résolution silencieuse/arbitraire au runtime** (FR13).

> Story **5/5 (dernière) de l'Epic 30** (« Cibler par labels — types de parc »). Elle livre **uniquement** la **prévention prédictive** : un **détecteur de collision verrou/verrou** réutilisable + son branchement aux **points d'assignation** (assignation d'un label à un `WorkstationGroup` — surface 30.2 ; rattachement d'un poste à un parc labellisé — `WorkstationGroupService`). Elle ne livre **PAS** : la résolution runtime par label (→ **30.4**, livré : warning `agent.state.conflict` + tiebreak), le mapping/refus de label (→ **30.2**, livré), la garantie d'existence des groupes imposés (→ **30.3**, livré). Elle clôt FR13 et l'Epic 30.

> **Frontière 30.4 ↔ 30.5 (héritée, à respecter).** 30.4 a posé la **résolution runtime observable** : deux `locked` contradictoires sur le même poste produisent deux candidats `StateMaille::Upstream` (rang -1) « tied-at-top » ⇒ warning `agent.state.conflict` émis par `StateCompiler::resolveExclusiveWinner` + tiebreak déterministe (`updated_at`/`sourceId`) pour ne pas servir d'état vide. **30.4 ne PRÉVIENT PAS et ne RÉSOUT PAS** ; elle l'a explicitement documenté comme relevant de 30.5 (cf. PHPDoc `UpstreamContractSource` l. 72-95 ; story 30.4 AC4). **30.5 = la PRÉVENTION proactive** : intercepter l'assignation qui *créerait* cette collision et l'**empêcher** avec un message explicite (item, périmètre, valeurs). Les deux mécanismes coexistent : 30.5 ferme la porte d'entrée ; 30.4 reste le filet de sécurité runtime pour les collisions résiduelles (contrat mis à jour APRÈS coup, anomalies de données).

## Acceptance Criteria

1. **AC1 — Détection d'une collision insoluble introduite par l'assignation d'un label.**
   **Given** deux labels `A` et `B` du contrat amont **actif**, chacun cible d'un item amont **`locked`** imposant la **même** propriété exclusive (même `exclusiveKey()` côté provider : ex. `registry` même `hive|path|name`) avec des **valeurs différentes** (`A` ⇒ X=1, `B` ⇒ X=2), un `WorkstationGroup` `G_A` portant `controlhub_label = A` dont **au moins un poste** est membre, et ce **même** poste membre d'un autre `WorkstationGroup` `G_B`,
   **When** le refnum tente d'assigner le label `B` à `G_B` (via {@see WorkstationGroupLabelService::assignLabel()}),
   **Then** SE5 **détecte la collision AVANT toute écriture** et **refuse** l'assignation en levant une exception de domaine **affichable** (toast) qui nomme explicitement : **(a)** la propriété / l'item en conflit (clé exclusive + `rule_ids`/`sourceId` des deux items), **(b)** le **périmètre** (le ou les postes touchés + les deux labels/parcs en cause), **(c)** les **deux valeurs** contradictoires.
   **And** la colonne `controlhub_label` de `G_B` reste **inchangée** (aucune écriture, aucune résolution silencieuse).

2. **AC2 — Assignation SANS collision : aucun changement de comportement (30.2 préservé).**
   **Given** un label `B` dont l'assignation à `G_B` n'introduit **aucune** collision verrou/verrou (les items `locked` portés par les labels que cumuleraient les postes de `G_B` ne se chevauchent sur **aucune** `exclusiveKey` avec des valeurs différentes — ou bien `B` n'a aucun item `locked`, ou les valeurs **concordent**),
   **When** le refnum assigne `B`,
   **Then** l'assignation **réussit exactement comme en 30.2** (colonne écrite, toast de succès) : la validation prédictive est **transparente** quand il n'y a pas de collision.
   **And** des valeurs **identiques** sur la même clé (X=1 via `A` ET X=1 via `B`) ne sont **PAS** une collision (rien à trancher — pas de contradiction) : l'assignation passe.

3. **AC3 — Seul VERROU/VERROU contradictoire bloque ; permissif jamais.**
   **Given** une assignation où la propriété partagée est imposée par un item **`permissive`** (d'au moins un des deux côtés) — ou par un `locked` d'un côté et `permissive`/absent de l'autre,
   **When** le refnum assigne le label,
   **Then** **aucun** blocage n'est levé : un permissif est un **plancher** surchargeable (FR4/29.3) — il **ne peut pas** entrer en collision insoluble (la règle verrou/permissif de 30.4 tranche déjà au runtime sans état vide). Seules **deux propriétés `locked` contradictoires** sur la **même** `exclusiveKey` du **même** poste constituent une collision (FR13). Les items `absent` sont **ignorés** (n'imposent rien).

4. **AC4 — Interception au rattachement d'un poste à un parc labellisé (« lier un parc »).**
   **Given** un poste qui porte déjà (via un parc) un label `A` cible d'un item `locked` sur la clé X=1, et un `WorkstationGroup` `G_B` portant `controlhub_label = B` cible d'un item `locked` sur la **même** clé X=2,
   **When** le refnum **rattache ce poste** à `G_B` (méthodes d'ajout d'appartenance de {@see \App\Services\Parc\WorkstationGroupService} : `addMachineToGroup` / `setMachineGroups` / `setGroupMachines` / `bulkAddMachinesToGroup`),
   **Then** SE5 **détecte la collision AVANT l'écriture du pivot** et **refuse** le rattachement (même exception de domaine, message explicite : poste, labels, clé, valeurs) — **aucune** ligne `workstation_group_workstation` ajoutée.
   **And** un rattachement à un parc **non labellisé**, ou à un parc dont le label n'introduit **aucune** collision, **réussit inchangé** (comportement parc standalone strictement préservé).

5. **AC5 — Réutilisation STRICTE du socle 30.4 ; D2 ne fuit pas (R3).**
   **Given** le code livré,
   **When** on l'inspecte,
   **Then** (a) la **prédiction** réutilise les candidats `locked` déjà construits par `UpstreamContractSource` (items `target_type = label`, maille `StateMaille::Upstream`, payload via les adaptateurs `UpstreamPayloadAdapter`) et la `exclusiveKey()` des providers exclusifs **existants** — **aucune** ré-implémentation du parsing de clé/valeur, **aucune** nouvelle notion de précédence ; (b) **aucune** ligne n'est ajoutée à `StateCompiler` (`specificity()`, `selectExclusive`, `resolveExclusiveWinner`), à `StateMaille`, ni à `AgentServiceProvider` — la prévention est un **concern d'assignation**, pas de compilation ; (c) **aucun** identifiant/message/test livré ne contient le mot **« central »** (R3) — vocabulaire `Upstream` / `ControlHub*` / `label` / « amont ».

6. **AC6 — Standalone & NFR3 (court-circuit obligatoire).**
   **Given** une instance **sans** contrat amont actif, **OU** un contrat actif **sans aucun** item `target_type = label` en état `locked`,
   **When** le refnum assigne un label libre / rattache un poste à un parc,
   **Then** le détecteur **court-circuite immédiatement** (zéro requête supplémentaire au-delà de la résolution du contrat déjà mémoïsée) et **aucun** blocage n'est introduit : le comportement 30.2 (assignation) et le comportement parc historique (rattachement) sont **strictement inchangés**. Aucun chemin parc existant ne se met à dépendre du contrat hors de la garde prédictive.

7. **AC7 — Déterminisme & complétude du périmètre rapporté (NFR4).**
   **Given** une assignation collisionnante touchant **plusieurs** postes,
   **When** la détection s'exécute,
   **Then** le résultat est **déterministe** (postes/labels/clés ordonnés de façon stable, indépendante du plan SQL) et le message **énumère** le périmètre touché de manière reproductible (au moins : la clé en conflit, les deux `sourceId`, les deux valeurs, les deux labels, et un échantillon stable des postes concernés — ou leur compte). Deux exécutions identiques produisent le **même** message.

8. **AC8 — Collision pré-existante NON imputable à l'assignation : ne pas bloquer à tort.**
   **Given** un poste qui cumule **déjà** (avant l'opération) deux labels `locked` contradictoires (collision pré-existante, déjà signalée au runtime par 30.4),
   **When** le refnum effectue une assignation/rattachement **qui n'aggrave pas** ce conflit (le label/parc ajouté n'apporte **aucun** nouvel item `locked` contradictoire sur une clé concernée par l'opération),
   **Then** l'opération **n'est pas bloquée** par 30.5 : la garde ne refuse que les collisions **introduites ou aggravées** par l'assignation en cours (au moins un des deux items en conflit provient du label/parc **ajouté**). (Les collisions pré-existantes restent du ressort du filet runtime 30.4 ; 30.5 ne « répare » pas le passé, il empêche d'en créer de nouvelles — pas de faux refus paralysant.)

## Tasks / Subtasks

- [x] **Task 1 — Exposer les candidats `locked` par label depuis le socle 30.4 (réutilisation, pas de re-requête)** (AC: #1, #3, #5, #6)
  - [x] Ajouter à `app/Services/ControlHub/Resolution/UpstreamContractSource.php` un **accesseur en lecture seule** `lockedLabelCandidates(): array` retournant, **filtrés à la maille `StateMaille::Upstream` (locked SEULEMENT)**, les candidats label déjà construits dans `$groupedByLabel` : forme `array<string $label, array<string $groupKey "providerType|scope", list<StateCandidate>>>`. **NE PAS** re-requêter ni re-parser : réutiliser `ensureResolved()` (mémoïsé) et `$this->groupedByLabel` (déjà peuplé par 30.4, même adaptateur/`toPayload`/`sourceId`). Filtrer `UpstreamPermissive` (rang 6) → exclu (AC #3 : un permissif ne collisionne jamais).
  - [x] **Court-circuit NFR3** : si `$groupedByLabel === []` (aucun item label, ou pas de contrat actif) ⇒ retour `[]` immédiat (cohérent avec le court-circuit de `candidatesFor()` l. 199-203). L'appelant (détecteur) doit traiter `[]` comme « rien à valider ».
  - [x] PHPDoc : préciser que cet accesseur sert la **prévention prédictive 30.5** (collision verrou/verrou à l'assignation), distincte de la résolution runtime ; il n'arbitre RIEN (pas de précédence — D2 reste dans `StateCompiler`).

- [x] **Task 2 — `UpstreamLockCollisionDetector` : cœur de la prédiction** (AC: #1, #2, #3, #6, #7, #8)
  - [x] Créer `app/Services/ControlHub/Resolution/UpstreamLockCollisionDetector.php` (`declare(strict_types=1)`, `final`). Injection : `UpstreamContractSource $source` + la liste des providers exclusifs (`KeyedExclusiveProvider`) déjà câblés (pour calculer `exclusiveKey($payload)` — **réutiliser**, ne pas ré-implémenter la dérivation de clé). Voir Dev Notes § « Calcul de l'exclusiveKey ».
  - [x] Construire une **table indexée des items `locked` par label** : `label → groupKey → exclusiveKey → list<{sourceId, value, payload}>`, à partir de `lockedLabelCandidates()` (Task 1) + `exclusiveKey()`. Ne retenir que les providers **exclusifs** (les aggregate type `shortcuts` ne collisionnent pas sur une clé unique — union ; les ignorer). Mémoïser cette table (par-requête).
  - [x] **API de prédiction** (deux entrées, une logique commune) :
    - `collisionsFromLabelGainedBy(iterable<Workstation> $workstations, string $newLabel, callable $existingLabelsOf): list<UpstreamLockCollision>` — pour chaque poste de la population touchée, calculer l'**ensemble des labels qu'il porterait** (`$existingLabelsOf($ws)` réinterprété pour intégrer/écraser le label modifié) ∪ `{$newLabel}`, puis détecter, **par `groupKey`+`exclusiveKey`**, ≥2 valeurs `locked` **distinctes** où **au moins une provient de `$newLabel`** (filtre AC #8 : collision **introduite** par l'ajout, pas pré-existante).
    - Helpers de surface (Task 3/4) qui calculent la population de postes + `existingLabelsOf` puis délèguent ici.
  - [x] **Égalité de valeur** : comparer les valeurs **normalisées** par l'adaptateur (`toPayload()['value']`, déjà typé `int`/`string`/`list<string>` — pas de float, §4.1). Valeurs **égales** ⇒ pas de collision (AC #2). Valeurs **différentes** ⇒ collision.
  - [x] **Déterminisme (NFR4)** : trier labels, clés, `sourceId`, postes de façon stable avant de composer le rapport (AC #7).
  - [x] **R3** : aucun « central ». Aucune écriture, aucune émission de candidat — service **pur lecture/prédiction**.

- [x] **Task 3 — Brancher l'interception « assigner un label »** (AC: #1, #2, #3, #6)
  - [x] Dans `app/Services/ControlHub/WorkstationGroupLabelService::assignLabel()`, **après** la matrice de refus existante (no-op idempotent / noActiveContract / unknown / reserved / alreadyLabeled) et **AVANT** `DB::transaction` qui écrit `controlhub_label` : appeler le détecteur sur la **population** des postes membres de `$group` (`$group->workstations`), avec `$newLabel = $labelName` et `existingLabelsOf` = labels actuellement portés par chaque poste **hors** le slot de `$group` (puisque `$group` portait au plus 1 label, qui va être remplacé par `$labelName`). Si une ou plusieurs collisions sont prédites ⇒ lever `UpstreamLockCollisionException` (Task 5) — **aucune** écriture.
  - [x] **NFR3** : si `lockedLabelCandidates()` est vide ⇒ le détecteur rend `[]` sans requête postes (court-circuit Task 1) ⇒ assignation 30.2 inchangée. La population `$group->workstations` n'est chargée que si la garde n'a pas court-circuité (lazy : passer un itérable/closure, ou tester le court-circuit en amont de l'eager-load).
  - [x] **Création de groupe + label** (page `groups/new`) : la création d'un groupe NEUF n'a **aucun** poste membre ⇒ aucune collision possible à la création (population vide) ; documenter qu'aucun branchement supplémentaire n'est requis côté création (le rattachement de postes ultérieur passe par Task 4).

- [x] **Task 4 — Brancher l'interception « lier un parc » (rattachement de postes)** (AC: #4, #6, #8)
  - [x] Dans `app/Services/Parc/WorkstationGroupService`, garder les **points d'ajout d'appartenance** : `addMachineToGroup()`, `setMachineGroups()`, `setGroupMachines()`, `bulkAddMachinesToGroup()` (cf. l. 892-1006). Via un **helper privé unique** (`guardUpstreamLockCollisionOnAttach(array $machineIds, array $targetGroupIds)`), AVANT l'écriture du pivot : si l'un des groupes cibles **porte un label** (`controlhub_label`) ET que `lockedLabelCandidates()` n'est pas vide, calculer pour chaque poste **gagnant** une appartenance les labels qu'il porterait (labels actuels ∪ labels des groupes cibles) et détecter une collision **introduite** par les labels ajoutés. Collision ⇒ `UpstreamLockCollisionException` — **aucune** écriture du pivot.
  - [x] **Bornage strict / NFR3** : court-circuit en TÊTE du helper — si **aucun** groupe cible ne porte de label OU `lockedLabelCandidates() === []` ⇒ `return` immédiat (zéro requête supplémentaire, hot-path parc intact). Ne PAS toucher les chemins de **détachement**/retrait (`removeMachineFromGroup`, `bulkRemove`) : retirer une appartenance ne peut pas *créer* de collision.
  - [x] `assignMachineToPhysicalRoom()` : la salle physique est en 1-max et rarement labellisée ; appliquer la même garde **uniquement si** la salle cible porte un label (sinon court-circuit). Cohérent avec « lier un parc ».
  - [x] Documenter en PHPDoc le périmètre des points gardés (ajout d'appartenance only) et le court-circuit NFR3.

- [x] **Task 5 — Exception de domaine `UpstreamLockCollisionException` + DTO de collision** (AC: #1, #4, #7)
  - [x] Créer `app/Exceptions/ControlHub/UpstreamLockCollisionException.php` (`extends RuntimeException`), message **FR affichable** (toast via `WithToasts`) listant : clé exclusive en conflit, les deux labels/parcs, les deux valeurs, les deux `sourceId`, et le périmètre (postes touchés ou leur compte). Fabrique statique `fromCollisions(list<UpstreamLockCollision> $collisions): self`. **Patron** : `app/Exceptions/ControlHub/LabelAssignmentException.php` (30.2) + `InvalidUpstreamContractException` (28.2). **R3** : aucun « central ».
  - [x] Créer un DTO immuable `app/Services/ControlHub/Resolution/UpstreamLockCollision.php` (`final readonly`) portant la collision **structurée** : `exclusiveKey`, `providerType`, `scope`, `array{label,sourceId,value}` ×2 (les deux côtés), et la liste/compte des `workstationId` touchés. Sert le message ET les tests (assertions structurées, pas par sous-chaîne fragile).

- [x] **Task 6 — Câblage conteneur** (AC: #5, #6)
  - [x] Enregistrer `UpstreamLockCollisionDetector` dans `app/Providers/AgentServiceProvider.php` (ou le provider ControlHub adéquat) en **singleton par-requête**, injectant le singleton `UpstreamContractSource` **déjà** enregistré (28.3) + la collection des providers `KeyedExclusiveProvider` exclusifs déjà construite pour le décorateur amont. **NE PAS** modifier la logique de décoration des providers ni l'ordre/liste (28.3) — uniquement ajouter le binding du détecteur. Confirmer qu'aucun changement de `StateCompiler`/`StateMaille` n'est requis (AC #5b).

- [x] **Task 7 — Tests HÔTE (php8.4 + sqlite, `RefreshDatabase`)** (AC: #1–#8)
  - [x] Créer `tests/Feature/ControlHub/UpstreamLockCollisionTest.php`. Réutiliser les helpers de `UpstreamContractResolutionTest` (`fakeProvider`/`keyedExclusiveProvider`, factories `ControlHubContract*Factory` états `active`/`forLabel($name)`/`locked`/`permissive`/`absent`, `WorkstationGroupFactory` + `controlhub_label`, `WorkstationFactory`). `WorkstationGroupObserver::disableSync()` / `Queue::fake()` pour neutraliser l'AD-sync au `WorkstationGroup::factory()->create()`.
  - [x] `assigning_label_introducing_locked_conflict_is_refused` (AC #1) : deux labels `A`/`B`, deux items `locked` même `exclusiveKey` valeurs ≠ ; un poste membre de `G_A` (label `A`) **et** de `G_B` (sans label) ; `assignLabel(G_B, 'B')` ⇒ `UpstreamLockCollisionException` (asserts structurés : clé, 2 sourceId, 2 valeurs, poste) ; `G_B->controlhub_label` reste `null` (aucune écriture).
  - [x] `assigning_label_without_conflict_succeeds` (AC #2) : clés disjointes ⇒ assignation OK (colonne écrite). Variante `same_key_same_value_is_not_a_collision` (X=1 des deux côtés) ⇒ OK.
  - [x] `permissive_overlap_does_not_block` (AC #3) : un des deux items `permissive` (ou l'un `locked` / l'autre `absent`) ⇒ assignation OK (pas de collision). `absent` ignoré.
  - [x] `attaching_workstation_to_labeled_parc_introducing_conflict_is_refused` (AC #4) : poste portant `A` (locked X=1) ; `G_B` label `B` (locked X=2) ; `addMachineToGroup($ws, G_B)` ⇒ exception, **aucune** ligne pivot ajoutée (assert `assertDatabaseMissing` / count pivot). Variante parc **non labellisé** ⇒ rattachement OK. Couvrir aussi `bulkAddMachinesToGroup`.
  - [x] `standalone_and_no_locked_label_items_short_circuit` (AC #6) : (a) sans contrat actif, (b) contrat actif sans item `label` `locked` ⇒ assignation/rattachement **inchangés** + assertion `DB::enableQueryLog()` : **aucune** requête postes/labels imputable au détecteur (court-circuit prouvé). Comportement 30.2/parc byte-équivalent.
  - [x] `detection_is_deterministic` (AC #7) : collision multi-postes ⇒ message/DTO **identiques** sur deux exécutions (et via `travel()` pour la stabilité temporelle). Périmètre énuméré de façon stable.
  - [x] `preexisting_conflict_not_aggravated_is_not_blocked` (AC #8) : poste cumulant déjà `A`/`B` en collision ; une assignation **orthogonale** (label `C` sans item `locked` sur la clé en conflit) ⇒ **non bloquée** (la garde ne refuse que les collisions impliquant le label ajouté).
  - [x] `r3_no_central_identifier` (AC #5c) : introspection des fichiers livrés (FQCN + littéraux de chaîne, patron `UpstreamContractResolutionTest`) — aucun « central ».
  - [x] **Anti-régression D2 (AC #5b)** : test/garde vérifiant **zéro** ligne ajoutée à `app/Services/Agent/StateCompiler.php` et `app/Enums/StateMaille.php` (ou au minimum : la suite `--filter StateCompiler` reste verte sans modification de ces fichiers).
  - [x] **Non-régression** : `--filter UpstreamContractResolution` (30.4 vert — l'accesseur Task 1 n'altère pas `candidatesFor`), `--filter WorkstationGroupLabel` (30.2 vert), `--filter StateCompiler`, suite pages parc / `WorkstationGroup` (Task 4 ne casse pas les rattachements standalone).
  - [x] **Pièges SQLite** : invariants prouvés par **comportement** (refus + état DB inchangé) + **comptage de requêtes**, jamais par contrainte varchar/unicité NULL. [mémoire `sqlite_tests_no_varchar_enforcement`]

- [x] **Task 8 — Doc QA (append-only)** (observabilité)
  - [x] **Enrichir** `docs/qa/domains/controlhub-contract.md` d'une **nouvelle section** « Story 30.5 — Validation prédictive à l'assignation » (append ; ne jamais réécrire les sections existantes) : collision verrou/verrou interceptée à l'assignation d'un label et au rattachement d'un parc labellisé, message attendu (item/périmètre/valeurs), distinction prévention 30.5 vs filet runtime 30.4, court-circuit standalone. Mettre à jour la ligne `controlhub-contract` de `docs/qa/README.md` (append, mentionner 30.5).

- [x] **Task 9 — Validation finale** (AC: #5, #6)
  - [x] `CACHE_DRIVER=array php artisan test --filter UpstreamLockCollision` (HÔTE) → vert (l'hôte n'a pas APCu ; `vendor/bin/phpunit` lit `phpunit.xml`). [mémoires `apcu_cache_no_lock`, `phpunit_test_env_host_vs_vm`]
  - [x] `--filter 'UpstreamContractResolution|WorkstationGroupLabel|StateCompiler|ControlHubContract|Imposed'` → verts (non-régression 28.x/29.x/30.x).
  - [x] `grep -riE central` sur les fichiers livrés → vide (R3). Confirmer **zéro** ligne nouvelle dans `StateCompiler.php` / `StateMaille.php` / la décoration des providers de `AgentServiceProvider.php` (AC #5b).

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 30.5

**DANS** : (1) accesseur lecture seule `UpstreamContractSource::lockedLabelCandidates()` (réutilise `$groupedByLabel` de 30.4, filtré `Upstream`/locked) ; (2) `UpstreamLockCollisionDetector` (prédiction pure d'une collision verrou/verrou contradictoire sur une même `exclusiveKey` d'un même poste, introduite par un label ajouté) ; (3) `UpstreamLockCollisionException` + DTO `UpstreamLockCollision` (message FR affichable + périmètre structuré) ; (4) branchement aux **points d'assignation** : `WorkstationGroupLabelService::assignLabel` (label→parc) et les méthodes d'**ajout d'appartenance** de `WorkstationGroupService` (poste→parc labellisé) ; (5) court-circuit NFR3 partout ; (6) tests HÔTE + doc QA append.

**HORS** (ne pas déborder — chaque point a sa story / son epic) :
- **Résolution runtime par label** (warning `agent.state.conflict`, tiebreak, injection des candidats) → **30.4** (livré). 30.5 ne touche PAS `UpstreamContractSource::candidatesFor()` ni `StateCompiler`.
- **Changement de `StateCompiler` / `StateMaille` / `specificity()` / décoration des providers (28.3)** → **interdit** (AC #5b). 30.5 lit le socle, ne le modifie pas.
- **Mapping/refus de label** (free/reserved/unknown/1-max) → **30.2** (livré). 30.5 ajoute UNE garde supplémentaire dans `assignLabel`, après la matrice 30.2, sans la réécrire.
- **Garantie d'existence / verrou des groupes imposés** → **30.3** (livré).
- **Représentation canonique du payload / nouveaux adaptateurs de type** → **Epic 33**. 30.5 réutilise les adaptateurs câblés (`registry` exclusif ; `shortcuts` aggregate = jamais en collision de clé).
- **Rupture/release de masse du lien** → **Epic 32**.
- **Adoucissement du warning runtime sur valeurs concordantes** (mentionné en option par 30.4 PHPDoc l. 83-95) → **hors 30.5** : 30.5 prévient à l'assignation, ne retouche pas `resolveExclusiveWinner`.

### Pourquoi « à l'assignation » et pas « à la compilation » — le bon point d'extension

La collision insoluble naît d'un **cumul d'appartenances** : un poste membre de plusieurs parcs cumule plusieurs labels (1 label max **par groupe**, mais appartenance multiple — cf. PRD §5 l. 99-101). Deux items `locked` ciblant deux de ces labels, sur la **même** `exclusiveKey` avec des valeurs ≠, sont **irréconciliables** (aucune n'est « la bonne » ; FR12 interdit toute spécificité inter-parcs pour départager — cf. 30.4). 30.4 a tranché la frontière : **résolution runtime observable** (filet) côté `StateCompiler` ; **prévention proactive** (porte d'entrée) côté **assignation**. Les deux **points d'assignation** sont les seuls moments où un poste *gagne* un label : (1) on assigne un label à un parc dont il est membre ; (2) on le rattache à un parc déjà labellisé. Intercepter là — avant l'écriture — empêche la contradiction d'exister. Mettre cette logique dans `TargetContext`/`StateCompiler` serait **faux** (compilation = trop tard, et casserait NFR3/D2 — cf. 30.4 Dev Notes « Pourquoi la source et pas TargetContext »).

### Calcul de l'`exclusiveKey` — réutiliser les providers, ne rien ré-implémenter (AC #5a)

Le détecteur a besoin, pour deux candidats `locked`, de savoir s'ils visent la **même** propriété. C'est **exactement** `KeyedExclusiveProvider::exclusiveKey($payload)` (ex. `registry` : `strtolower("$hive|$path|$name")` — cf. `RegistryUpstreamAdapter` doc l. 16-24 qui aligne déjà la `key` amont sur cette forme). Le détecteur **délègue** à ces providers (mêmes instances que celles décorées par 28.3, déjà câblées) pour keyer les candidats `locked` — **aucune** dérivation de clé réinventée (discipline identique à 30.4 réutilisant `specificity()`). Les providers **aggregate** (non `KeyedExclusiveProvider`, ex. `shortcuts`) ont une sémantique d'**union** (pas de clé exclusive unique) ⇒ **jamais** de collision insoluble ⇒ exclus du détecteur. Les candidats sont déjà groupés par `groupKey = providerType|scope` dans la source (la portée discrimine HKLM/machine vs HKCU/session — deux clés distinctes ne collisionnent pas) : ne comparer que des candidats de **même** `groupKey`.

### Algorithme de détection (résumé exécutable)

```
locked := source.lockedLabelCandidates()            // label → groupKey → [StateCandidate] (maille Upstream only)
if locked == [] : return []                          // NFR3 court-circuit (AC #6)

// index : label → groupKey → exclusiveKey → [{sourceId, value}]
index := build(locked, exclusiveKeyOf(provider, payload))

// pour l'assignation de $newLabel à une population de postes :
for ws in affectedWorkstations:
    labels := existingLabelsOf(ws) ∪ {newLabel}      // labels post-opération
    for groupKey, exclusiveKey in clés couvertes par 'labels':
        vals := { (label, sourceId, value) : label ∈ labels, présent dans index }
        distinctValues := unique(value de vals)
        if count(distinctValues) >= 2 AND ∃ entrée de vals issue de newLabel:   // AC #8
            collisions += UpstreamLockCollision(exclusiveKey, groupKey, les 2 côtés, ws)
return dedup/sort(collisions)                         // NFR4 (AC #7)
```

- `affectedWorkstations` : Task 3 (label→parc) = `$group->workstations` ; Task 4 (poste→parc) = les postes rattachés.
- `existingLabelsOf(ws)` : labels portés par les **autres** parcs du poste — réutiliser la même lecture que 30.4 (`WorkstationGroup.controlhub_label` via les appartenances du poste). Pour l'assignation à `$group`, **exclure** le label actuel de `$group` (il sera remplacé). Pour le rattachement, partir des appartenances actuelles ∪ labels des groupes cibles.
- **Filtre AC #8** : ne flaguer que si au moins un côté provient du label/parc **ajouté** (sinon c'est une collision pré-existante, ressort de 30.4 — ne pas bloquer).

### Code réel à réutiliser (ancrage exact — vérifié)

- `app/Services/ControlHub/Resolution/UpstreamContractSource.php` (30.4) — `$groupedByLabel` (l. 141-148, `label → groupKey → list<StateCandidate>`, locked **et** permissif), `ensureResolved()` (l. 259-337, mémoïsé, court-circuit sans contrat), `candidatesFor()` court-circuit l. 199-203, `groupKey()` l. 339-342. **Ajouter** `lockedLabelCandidates()` (filtre `StateCandidate->maille === StateMaille::Upstream`). Ne PAS toucher `candidatesFor()`.
- `app/Services/ControlHub/Resolution/UpstreamPayloadAdapter.php` — `providerType()`, `scopeFor()`, `toPayload()` (valeur normalisée). `RegistryUpstreamAdapter` (exclusif, `registry`) ; `ShortcutsUpstreamAdapter` (aggregate, exclu du détecteur).
- `app/Services/Agent/Contracts/KeyedExclusiveProvider.php` — `exclusiveKey(array $payload): string` (la clé à réutiliser). Providers exclusifs câblés par `AgentServiceProvider` (28.3).
- `app/Services/Agent/StateCandidate.php` — `maille` (filtrer `Upstream`), `payload`, `sourceId`, `updatedAt`. `app/Enums/StateMaille.php` — `Upstream` (locked) vs `UpstreamPermissive` (permissif). **Ne PAS modifier.**
- `app/Services/ControlHub/WorkstationGroupLabelService.php` (30.2) — `assignLabel()` (l. 58-97 : matrice de refus puis `DB::transaction` d'écriture). **Insérer la garde prédictive entre la matrice et la transaction.** Patron exception : `LabelAssignmentException` (l. 1-62).
- `app/Services/Parc/WorkstationGroupService.php` — points d'ajout d'appartenance `addMachineToGroup` (l. 892), `setMachineGroups` (l. 918), `setGroupMachines` (l. 931), `bulkAddMachinesToGroup` (l. 944), `assignMachineToPhysicalRoom` (l. 1033). **Garder uniquement les ajouts** (pas les retraits).
- `app/Models/WorkstationGroup.php` — `controlhub_label` (30.2), `hasControlHubLabel()`/`controlHubLabel()`, `scopeCarryingControlHubLabel()`, relation `workstations()` (l. 162-170, pivot `workstation_group_workstation`).
- `app/Models/Workstation.php` — `physicalRooms()` (l. 157), `logicalGroups()` (l. 231) pour les labels actuellement portés.
- `app/Models/ControlHubContract.php` — `active()` (30.2, singleton `link_state = active`). `app/Models/ControlHubContractItem.php` — `enforcement_state` (`Locked`/`Permissive`/`Absent`), `target_type`/`target_label` (28.1). `app/Enums/ControlHubEnforcementState.php`.
- `app/Components/Traits/WithToasts.php` — `toastError($e->getMessage())` (les pages Livewire 30.2 capturent déjà `LabelAssignmentException` ; ajouter le `catch` de `UpstreamLockCollisionException` au même endroit).
- `tests/Feature/ControlHub/UpstreamContractResolutionTest.php` — helpers `fakeProvider`/`keyedExclusiveProvider`, factories, capture, patron R3 introspection.
- Factories : `database/factories/ControlHubContractFactory.php` (`active`), `ControlHubContractItemFactory.php` (`forLabel($name)`, `locked`/`permissive`/`absent`), `WorkstationGroupFactory.php` (+`controlhub_label`), `WorkstationFactory.php`.

### Garde-fous projet CRITIQUES

- **R3 — Vocabulaire (BLOQUANT)** : aucun « central » dans tout identifiant/message/test livré. Vocabulaire `Upstream`/`ControlHub*`/`label`/« amont ». [mémoires `project_contrat_manage_se5_upstream`, `legacy_central_vs_local_split` ; prd#R3]
- **NFR3 — Standalone préservé (CŒUR)** : sans contrat actif **ou** sans item `label` `locked`, **zéro** garde active, **zéro** requête supplémentaire, assignation 30.2 et rattachement parc **inchangés**. Court-circuit `lockedLabelCandidates() === []` obligatoire (test révélateur AC #6). [prd#NFR3]
- **NFR4 — Déterminisme** : rapport de collision stable (tri labels/clés/sourceId/postes). [AC #7]
- **D2 ne fuit pas / réutilisation du moteur** : la prévention vit dans un **service d'assignation** ; **interdit** de modifier `StateCompiler`/`StateMaille`/`specificity()`/la décoration des providers. Le détecteur **lit** le socle 30.4, n'introduit **aucune** précédence. [AC #5b ; mémoire `permissive_floor_least_specific`]
- **Permissif jamais bloquant** : seul `locked`/`locked` contradictoire est une collision ; `permissive` est un plancher surchargeable (jamais insoluble), `absent` n'impose rien. [AC #3 ; FR4/29.3]
- **Pas de faux refus** : ne bloquer que les collisions **introduites** par l'opération (AC #8) — ne pas paralyser l'admin sur un conflit pré-existant déjà géré au runtime par 30.4.
- **Racine = projet Laravel** (artisan/app à la racine). [mémoire `root_is_laravel`]
- **Worktree** : tests HÔTE uniquement, **jamais** la VM/serveurs. [mémoires `phpunit_test_env_host_vs_vm`, `feedback_worktree_no_vm_sync`]
- **Livewire** : ne jamais nommer une action `upload` (réservée) ; capturer l'exception → `toastError`, pas de redirect (cohérent 30.2). [mémoire `project_livewire_reserved_upload_method`]

### Project Structure Notes

- **Nouveaux** : `app/Services/ControlHub/Resolution/UpstreamLockCollisionDetector.php`, `app/Services/ControlHub/Resolution/UpstreamLockCollision.php` (DTO), `app/Exceptions/ControlHub/UpstreamLockCollisionException.php`, `tests/Feature/ControlHub/UpstreamLockCollisionTest.php`.
- **Modifiés** : `app/Services/ControlHub/Resolution/UpstreamContractSource.php` (accesseur `lockedLabelCandidates()` — additif, ne touche pas `candidatesFor`), `app/Services/ControlHub/WorkstationGroupLabelService.php` (garde dans `assignLabel`), `app/Services/Parc/WorkstationGroupService.php` (garde sur les ajouts d'appartenance), `app/Providers/AgentServiceProvider.php` (binding détecteur), les pages Livewire parc qui appellent `assignLabel`/le rattachement (ajout du `catch` → toast — déjà structuré en 30.2), `docs/qa/domains/controlhub-contract.md` (append), `docs/qa/README.md` (append).
- **AUCUNE migration** (aucune nouvelle colonne ; tout est dérivé du socle existant). **AUCUN** changement de `StateCompiler`/`StateMaille`/décoration des providers (à vérifier — AC #5b).

### Pièges identifiés

1. **NFR3 court-circuit** : oublier de court-circuiter avant d'eager-load `$group->workstations` ⇒ requête postes parasite en standalone ⇒ AC #6 échoue (comptage de requêtes). Tester le court-circuit `lockedLabelCandidates() === []` AVANT toute lecture de population.
2. **Égalité de valeur normalisée** : comparer les valeurs via `toPayload()['value']` (typé), pas la chaîne brute `item->value` (REG_DWORD `1` vs `"1"` doivent être égaux ; pas de float §4.1). Valeurs égales ⇒ pas de collision (AC #2).
3. **Filtre « introduite » (AC #8)** : sans le filtre « au moins un côté provient du label ajouté », on bloquerait des assignations orthogonales sur un conflit pré-existant ⇒ faux refus paralysant. Indispensable.
4. **Aggregate vs exclusif** : ne pas tenter de détecter une collision sur un provider `shortcuts` (union — pas d'exclusiveKey). Restreindre aux `KeyedExclusiveProvider`.
5. **Portée (groupKey)** : deux items même `name` mais hive HKLM vs HKCU = `groupKey` différents ⇒ PAS une collision. Comparer dans le même `groupKey` uniquement (déjà garanti par la structure de la source).
6. **Permissif** : ne JAMAIS bloquer si l'un des deux items est `permissive`/`absent` — filtrer à `StateMaille::Upstream` dès `lockedLabelCandidates()` (AC #3).
7. **Surfaces de rattachement** : `WorkstationGroupService` a plusieurs points d'ajout — centraliser la garde dans un helper privé unique appelé par chacun, plutôt que dupliquer (et ne garder QUE les ajouts).
8. **SQLite** : refus/état-DB-inchangé + comptage de requêtes ; jamais varchar/NULL.

### References

- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 30.5] — AC d'origine (collision insoluble interceptée à l'assignation, FR13), l. 302-313.
- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 30.4] — frontière 30.4/30.5 ; règle verrou/permissif sans spécificité inter-parcs, l. 286-300.
- [Source: _bmad-output/planning-artifacts/prd-contrat-manage-se5.md#FR13] — validation prédictive à l'assignation, détecte+signale avant application, pas de résolution silencieuse, l. 129 ; cas résiduel l. 102 / l. 160 (patron native-WPKG, validation prédictive).
- [Source: _bmad-output/implementation-artifacts/30-4-resolution-item-ciblant-label.md] — socle réutilisé (`UpstreamContractSource` items label, maille `Upstream`, `agent.state.conflict` runtime) ; frontière 30.4↔30.5 (« 30.5 PRÉVIENT, 30.4 OBSERVE »).
- [Source: _bmad-output/implementation-artifacts/30-2-mapping-label-refnum.md] — `WorkstationGroupLabelService::assignLabel` (point d'interception « assigner un label »), `LabelAssignmentException` (patron).
- [Source: app/Services/ControlHub/Resolution/UpstreamContractSource.php#groupedByLabel,#ensureResolved] — candidats label par label (locked+permissif) à exposer/filtrer.
- [Source: app/Services/ControlHub/Resolution/UpstreamPayloadAdapter.php ; RegistryUpstreamAdapter.php] — payload normalisé + alignement de clé sur l'`exclusiveKey` registry.
- [Source: app/Services/ControlHub/WorkstationGroupLabelService.php#assignLabel] — insertion de la garde prédictive.
- [Source: app/Services/Parc/WorkstationGroupService.php#addMachineToGroup,#setMachineGroups,#setGroupMachines,#bulkAddMachinesToGroup,#assignMachineToPhysicalRoom] — points de rattachement à garder.
- [Source: app/Models/WorkstationGroup.php#workstations,#scopeCarryingControlHubLabel] — population de postes + label porté.
- [Source: app/Services/Agent/StateCompiler.php#specificity,#resolveExclusiveWinner] — INCHANGÉS (filet runtime 30.4) ; rappel des rangs `Upstream` -1 / `UpstreamPermissive` 6.

## Dépendances

- **Amont (bloquantes) — toutes satisfaites dans le code (à vérifier sur la branche, ≠ sprint-status)** :
  - **30.4 — `review`, code présent (FONDATION DIRECTE)** : `UpstreamContractSource` charge et groupe les items `target_type = label` par `target_label` (`$groupedByLabel`), construit les candidats `locked → StateMaille::Upstream` / `permissive → UpstreamPermissive` via les adaptateurs, et documente explicitement que la **prévention prédictive est 30.5** (PHPDoc l. 72-95). 30.5 **expose en lecture** (`lockedLabelCandidates()`) ce que 30.4 a déjà construit. Sans 30.4, il n'y a pas de candidats label à inspecter.
  - **30.2 — `review`, code présent** : colonne `workstation_groups.controlhub_label`, `WorkstationGroupLabelService::assignLabel` (point d'interception #1), `ControlHubContract::active()`, scope `carryingControlHubLabel()`, `LabelAssignmentException` (patron d'exception affichable + capture toast Livewire). 30.5 **insère** sa garde après la matrice 30.2.
  - **30.1 — `review`, code présent** : réception/persistance des labels (mode `free`/`reserved`) et durcissement `label_name` ; fournit le vocabulaire de ciblage cohérent (labels déclarés en amont) sur lequel la prédiction s'appuie. (Réception FR9 absorbée par les fondations Epic 28 — 28.1 schéma `controlhub_contract_labels` + 28.2 ingestion.)
  - **28.3 — `review`, code présent** : `UpstreamAwareProvider`, décoration des providers, maille `StateMaille::Upstream` + rang -1 dans `specificity()`, `KeyedExclusiveProvider::exclusiveKey()` réutilisé pour keyer les candidats. **29.3 — `review`** : maille `UpstreamPermissive` (filtrée → exclue de la détection).
  - > **Vérification CODE (≠ sprint-status)** : tous les artefacts 28.x/29.x/30.x sont présents et exploitables sur la branche rebasée unifiée. `UpstreamContractSource::$groupedByLabel` (30.4) est en place ; il suffit de l'exposer filtré `Upstream`. Aucun blocage de build.
- **Aval** :
  - **Aucune** : 30.5 clôt l'Epic 30 (dernier maillon FR13). Les évolutions ultérieures (rupture de lien Epic 32, schéma d'échange figé Epic 33) sont indépendantes.

## Testing

- **Cible : HÔTE** (php8.4 + `pdo_sqlite`), **jamais la VM**. Lancer avec `CACHE_DRIVER=array` (hôte sans APCu). `vendor/bin/phpunit` lit `phpunit.xml` (`DB_CONNECTION=sqlite`, `RefreshDatabase`). [mémoires `phpunit_test_env_host_vs_vm`, `apcu_cache_no_lock`]
- Filtres : `--filter UpstreamLockCollision` (cœur) ; non-régression `--filter 'UpstreamContractResolution|WorkstationGroupLabel|StateCompiler|ControlHubContract|Imposed'` + suite pages parc / `WorkstationGroup`.
- Couverture : collision verrou/verrou à l'assignation d'un label → refus + DB inchangée (AC1) ; pas de collision / valeurs égales → succès (AC2) ; permissif/absent jamais bloquant (AC3) ; collision au rattachement d'un poste à un parc labellisé → refus + pivot inchangé (AC4) ; standalone/sans item label locked → court-circuit + zéro requête (AC6) ; déterminisme du rapport via `travel()` (AC7) ; collision pré-existante non aggravée → non bloquée (AC8) ; R3 + zéro changement `StateCompiler`/`StateMaille` (AC5).
- **Neutraliser l'AD-sync** au `WorkstationGroup::factory()->create()` : `Queue::fake()` ou `WorkstationGroupObserver::disableSync()` (pas de LDAP en HÔTE). [mémoire `test_suite_env_and_systemic_fixes`]
- **Pièges SQLite** : refus + état DB + comptage de requêtes ; jamais varchar/unicité NULL. [mémoire `sqlite_tests_no_varchar_enforcement`]

## Recommandation Modèle Dev

**`opus`.**

Justification : story **dense en logique métier et en discipline de réutilisation**, malgré une surface modérée :
1. **Détection verrou/verrou contradictoire** : raisonnement ensembliste (cumul de labels par poste, comparaison par `exclusiveKey`+portée, égalité de valeur **normalisée**, filtre « introduite vs pré-existante » d'AC #8) où une erreur produit soit un **faux refus paralysant**, soit un **trou** (collision laissée passer) — sensibilité à la cohérence élevée.
2. **Réutilisation STRICTE du socle 30.4 sans le déformer** : exposer `$groupedByLabel` filtré `Upstream`, déléguer l'`exclusiveKey()` aux providers existants, **ne PAS** toucher `StateCompiler`/`specificity()`/décoration — la tentation de « recalculer » ou de glisser une précédence (fuite D2) est exactement l'anti-pattern interdit.
3. **NFR3 byte-équivalent + comptage de requêtes** : court-circuit obligatoire avant tout eager-load de population, sur deux familles de surfaces (assignation de label + rattachement de parc) — non-régression fine que des tests fonctionnels seuls ne révèlent pas.
4. **Multi-surfaces d'interception** (`assignLabel` + 4-5 méthodes de `WorkstationGroupService`) à garder via un helper unique, sans casser les chemins parc standalone (hot-path).

Le dev-cycle routera la **review vers le modèle opposé** (sonnet/fable) ; placer **opus** sur l'implémentation met le raisonnement là où le risque (faux refus, fuite D2, régression standalone, collision non détectée) est maximal. Cohérent avec 30.1/30.2/30.3/30.4 (toutes en opus).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m]

### Debug Log References

- Régression initiale `tests/Unit/Services/Parc/PhysicalRoomSwapTest` (5 erreurs) :
  ce test unitaire construit un schéma SQLite ad-hoc PARTIEL (4 tables, sans
  `controlhub_*`). La garde `assignMachineToPhysicalRoom` sonde `controlhub_contracts`
  via le court-circuit NFR3 → `no such table`. **Fix** : ajout d'une table
  `controlhub_contracts` minimale (vide ⇒ aucun contrat actif ⇒ garde inerte) +
  colonne `controlhub_label` au schéma ad-hoc + drop au tearDown. Aucune logique de
  prod modifiée (en prod la table existe toujours).
- `php artisan test` classe les tests `ControlHub` en `WARN` (bruit
  `file_get_contents` global du wrapper Collision — présent AUSSI sur les tests 30.4
  existants). Runner canonique `vendor/bin/phpunit` (lit `phpunit.xml`) : **propre**.

### Completion Notes List

- **Task 1** — `UpstreamContractSource::lockedLabelCandidates()` : accesseur lecture
  seule, filtre `$groupedByLabel` à `StateMaille::Upstream` (locked SEUL ; permissif
  exclu = AC #3), court-circuit `[]` (NFR3). Réutilise `ensureResolved()` mémoïsé,
  zéro re-requête. `candidatesFor()` INTACT.
- **Task 2** — `UpstreamLockCollisionDetector` (`final`, pur lecture) : index
  `label → groupKey → exclusiveKey → {sourceId,value}` keyé par l'`exclusiveKey()`
  des `KeyedExclusiveProvider` EXISTANTS (aggregate exclu). API
  `collisionsFromLabelGainedBy` / `collisionsFromLabelsGainedBy` ; égalité de valeur
  NORMALISÉE (`toPayload()['value']`, DWORD 1 == "1" ; listes via json) ; filtre AC #8
  (au moins un côté gagné) ; tri déterministe (NFR4). Helper
  `carriedLabelsExcludingGroups()` (1 requête, anti-N+1) + `hasLockedLabelItems()`
  (court-circuit NFR3).
- **Task 3** — garde dans `WorkstationGroupLabelService::assignLabel` (constructeur
  détecteur nullable → résolution paresseuse `app()` ; préserve `new
  WorkstationGroupLabelService()` des tests 30.2). Court-circuit AVANT eager-load de
  `$group->workstations`. Création de groupe neuf = population vide ⇒ aucune garde.
- **Task 4** — `WorkstationGroupService` : 4e param constructeur nullable + helper
  privé UNIQUE `guardUpstreamLockCollisionOnAttach()` câblé sur addMachineToGroup /
  setMachineGroups / setGroupMachines / bulkAddMachinesToGroup /
  assignMachineToPhysicalRoom (cette dernière seulement si la salle porte un label).
  Court-circuit en TÊTE (`hasLockedLabelItems()` puis « aucun groupe cible labellisé »).
  Retraits NON touchés.
- **Task 5** — `UpstreamLockCollisionException` (`fromCollisions()`, message FR toast)
  + DTO `UpstreamLockCollision` (`final readonly`, côtés structurés + périmètre trié).
- **Task 6** — binding singleton `UpstreamLockCollisionDetector` dans
  `AgentServiceProvider` injectant le singleton `UpstreamContractSource` + les
  providers registry exclusifs. Décoration des providers / `StateCompiler` /
  `StateMaille` INCHANGÉS (AC #5b).
- **Task 7** — `UpstreamLockCollisionTest` (16 tests, 310 assertions) couvrant
  AC #1–#8 + R3 (introspection) + anti-régression D2 (StateCompiler/StateMaille ne
  référencent pas 30.5, `StateMaille::cases()` reste à 8).
- **Task 8** — doc QA : Section 13 + checklist (append) ; ligne `controlhub-contract`
  du README (append, 30.5).
- **Task 9** — validation : `UpstreamLockCollision` 16/16 ; non-régression
  `UpstreamContractResolution|WorkstationGroupLabel|StateCompiler|ControlHubContract|Imposed`
  143/143 ; suite parc `WorkstationGroup|Parc|Machine` 536/536 (1 skip pré-existant).
  R3 grep = commentaires garde-fou only ; D2 git diff moteur = vide.
- **Post-review (30-5.md)** : refactor helper garde en modèle pré-set/post-set par
  poste (corrige #1/#2/M1 faux refus sur surfaces sync/swap) + tests
  setMachineGroups/setGroupMachines/assignMachineToPhysicalRoom ; M2 fail-closed acté.
  Détecteur : nouveau cœur générique `collisionsFromFinalState($ws, $preLabelsOf,
  $postLabelsOf)` (`gained = post \ pre`) ; `collisionsFromLabelsGainedBy` y délègue
  (additif `post = pre ∪ gagnés`). Service : helper unique `guardUpstreamLockCollision`
  (pre = TOUS les labels portés, post par surface — additif / remplacement
  `groups()->sync()` = labels(groupIds) seuls / swap salle = `pre \ labels(salles
  physiques courantes) ∪ label cible`). Court-circuit NFR3 en tête préservé sur
  chaque surface. Validation HÔTE : `UpstreamLockCollision` 22/22 (334 assertions) ;
  `UpstreamContractResolution|WorkstationGroupLabel|StateCompiler|ControlHubContract`
  117/117 (736 assertions) ; `WorkstationGroup|Parc|Machine|PhysicalRoom` 540/540
  (1515 assertions), zéro régression ; `git diff` StateCompiler/StateMaille/
  AgentServiceProvider = vide.

### File List

**Créés**
- `app/Services/ControlHub/Resolution/UpstreamLockCollisionDetector.php`
- `app/Services/ControlHub/Resolution/UpstreamLockCollision.php`
- `app/Exceptions/ControlHub/UpstreamLockCollisionException.php`
- `tests/Feature/ControlHub/UpstreamLockCollisionTest.php`

**Modifiés**
- `app/Services/ControlHub/Resolution/UpstreamContractSource.php` (accesseur `lockedLabelCandidates()`, additif)
- `app/Services/ControlHub/WorkstationGroupLabelService.php` (constructeur + garde dans `assignLabel`)
- `app/Services/Parc/WorkstationGroupService.php` (4e param constructeur + helper + câblage des 5 surfaces d'ajout)
- `app/Providers/AgentServiceProvider.php` (binding détecteur — décoration providers inchangée)
- `resources/views/pages/parc/groups/[id]/edit/index.blade.php` (catch toast)
- `resources/views/pages/parc/groups/new/index.blade.php` (catch toast)
- `resources/views/pages/parc/index.blade.php` (catch toast)
- `resources/views/pages/parc/groups/[id]/index.blade.php` (catch toast)
- `resources/views/pages/parc/machines/[id]/index.blade.php` (catch toast)
- `tests/Unit/Services/Parc/PhysicalRoomSwapTest.php` (schéma ad-hoc : table `controlhub_contracts` + colonne `controlhub_label`)
- `docs/qa/domains/controlhub-contract.md` (Section 13 + checklist, append)
- `docs/qa/README.md` (ligne `controlhub-contract`, append 30.5)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (30-5 → review)
