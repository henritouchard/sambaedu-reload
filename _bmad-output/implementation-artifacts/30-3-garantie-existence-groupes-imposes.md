# Story 30.3: Garantie d'existence des groupes imposés

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **SE5 (le système)**,
I want **garantir l'existence des `WorkstationGroup` exigés par le contrat amont (controlHub) — les CRÉER avec leur label réservé s'ils sont absents, CONFIRMER leur existence + label sans duplication s'ils existent, et EMPÊCHER le refnum de les supprimer tant que le contrat les exige**,
so that **les groupes imposés (ex. `bureau_direction`, `compta_x`) existent toujours, portent leur label réservé, et ne peuvent être détruits sous le contrat — sans que rien ne change en standalone** (FR11).

> Story **3/5** de l'Epic 30 (« Cibler par labels — types de parc »). Elle livre **uniquement** la **réconciliation « désir d'état » des groupes imposés** (création/confirmation idempotente) + le **verrou de suppression** des groupes sous contrat. Elle ne touche **ni** `StateCompiler` (résolution par label → 30.4), **ni** la validation prédictive de collision (→ 30.5), **ni** le mapping refnum d'un label *libre* (déjà livré en 30.2), **ni** la rupture/release du lien (→ Epic 32).

> **Décisions de design clés (à respecter — héritées de 28.1/30.2)** :
> 1. **Aucune migration** : les 3 colonnes nécessaires existent déjà sur `workstation_groups` — `controlhub_label` (30.2, `string` nullable), `managed_by_control_hub` (`boolean`, schéma unifié), `locked` (`string` nullable, migration `2026_02_05_160948`). La story 30.3 est **100 % logique/service**, pas de schéma.
> 2. **Rattachement label↔groupe PAR NOM** (`controlhub_label` = nom du label), **jamais** de FK dure. Robuste à la réconciliation prune/recreate des labels (28.2) et à la rupture du lien (Epic 32). [cf. 28.1 `imposed_groups.label_name`, 30.2 Dev Notes]
> 3. **Réutiliser le chemin de création parc existant** (`WorkstationGroupService::createGroup()` → `WorkstationGroupObserver::created()` → `WorkstationGroupAdSyncJob`) pour que la création d'un groupe imposé **écrive réellement dans l'AD/LDAP** (groupe logique `OU=Parcs`). **Ne pas** créer un chemin parallèle SQL-only.
> 4. **Verrou de suppression = mécanisme `locked` existant** : `WorkstationGroupService::deleteGroup()` **throw déjà** sur `isLocked()`, et l'UI désactive déjà le bouton « Supprimer » quand `isLocked()`. Poser `locked = LockReason::CONTROL_HUB` sur un groupe imposé **réutilise** ce garde-fou — ne pas réinventer une logique de refus de suppression.

---

## Acceptance Criteria

1. **AC1 — Création d'un groupe imposé absent (réconciliation).**
   **Given** le contrat amont **actif** impose un groupe nommé `G` (avec, optionnellement, un `label_name` réservé `L`),
   **And** aucun `WorkstationGroup` nommé `G` n'existe,
   **When** la réconciliation des groupes imposés s'exécute,
   **Then** un `WorkstationGroup` `G` est **créé** via le chemin parc existant (`WorkstationGroupService::createGroup()`), avec `is_physical = false` (parc logique), `managed_by_control_hub = true`, `locked = control_hub` (verrou de suppression), et `controlhub_label = L` si un label est associé (sinon `null`). La création dispatche la synchro AD existante (`WorkstationGroupAdSyncJob`), comme tout parc créé par l'UI.

2. **AC2 — Confirmation idempotente d'un groupe imposé existant (sans duplication).**
   **Given** un `WorkstationGroup` nommé `G` **existe déjà**,
   **When** la réconciliation s'exécute pour un groupe imposé `G`,
   **Then** **aucun doublon** n'est créé (résolution par nom : il reste exactement **un** groupe `G`), son `controlhub_label` est aligné sur le `label_name` imposé (posé s'il était vide/différent), `managed_by_control_hub` passe à `true`, et le verrou de suppression `locked = control_hub` est garanti **sans écraser** un verrou de plus forte priorité déjà présent (ex. `root`).

3. **AC3 — Idempotence sur exécutions répétées.**
   **Given** un contrat dont les groupes imposés ont déjà été réconciliés,
   **When** la réconciliation s'exécute **une seconde fois** (mêmes données),
   **Then** elle est un **no-op fonctionnel** : aucun doublon, aucune exception, le résultat est stable (mêmes flags/label) et idempotent (NFR4).

4. **AC4 — Verrou de suppression sous contrat (refnum bloqué).**
   **Given** un groupe imposé `G` réconcilié (donc `locked = control_hub`),
   **When** le refnum tente de le supprimer (service `WorkstationGroupService::deleteGroup()` ou action UI),
   **Then** la suppression est **refusée** (le mécanisme `isLocked()` existant lève/empêche), `G` **persiste**, et l'UI présente le groupe comme **non supprimable** (bouton « Supprimer » désactivé + indication « imposé par l'amont »).

5. **AC5 — Déclenchement automatique à la réception du contrat.**
   **Given** une ingestion de contrat amont qui **mute** les groupes imposés (création/upsert/prune — émet `ControlHubContractChanged`),
   **When** l'événement est dispatché (après commit de l'ingestion 28.2),
   **Then** la réconciliation des groupes imposés est **déclenchée automatiquement** (listener abonné à `ControlHubContractChanged`) et l'état des `WorkstationGroup` reflète le contrat — **sans** que l'ingestion 28.2 elle-même soit modifiée (elle émettait déjà l'événement, jusque-là inerte).

6. **AC6 — Levée du verrou quand le contrat n'exige plus le groupe.**
   **Given** un groupe `G` précédemment imposé (`locked = control_hub`, `managed_by_control_hub = true`),
   **And** le contrat amont **actif** **ne** liste **plus** `G` parmi ses groupes imposés (réconciliation prune côté 28.2),
   **When** la réconciliation s'exécute,
   **Then** le **verrou de suppression `control_hub` est levé** (`locked` repasse à `null` **uniquement** s'il valait `control_hub` ; un verrou `root` est préservé) et `managed_by_control_hub` repasse à `false`, **sans supprimer** le groupe (la suppression effective reste un geste refnum ; la rupture totale du lien relève d'Epic 32). Le `controlhub_label` réservé devenu « dangling » est laissé tel quel (sans effet — cf. 30.2 / 30.4).

7. **AC7 — Standalone & robustesse (NFR3, NFR4).**
   **Given** une installation SE5 **sans** contrat amont actif (`ControlHubContract::active() === null`),
   **When** la réconciliation est invoquée (ou l'événement ne survient jamais),
   **Then** elle est un **no-op total** : aucun `WorkstationGroup` créé/modifié, aucun verrou posé, comportement parc **strictement inchangé** (NFR3). Une réception répétée du même contrat reste idempotente (NFR4).

8. **AC8 — R3 / vocabulaire.**
   **Given** l'ensemble des artefacts livrés (service, listener, commande, exception éventuelle, messages, commentaires, tests, doc),
   **When** on les inspecte,
   **Then** **aucun** identifiant ou texte livré ne contient le mot **« central »** (R3) ; le vocabulaire reste « amont » / `ControlHub*` / `imposed` / `label`. (NB : la valeur d'enum **pré-existante** `LockReason::CONTROL_HUB = 'control_hub'` et sa description « Géré par ControlHub » sont conformes — « ControlHub » est le vocabulaire autorisé, pas « central » ; ne pas la renommer.)

## Tasks / Subtasks

- [x] **Task 1 — Service de réconciliation `ImposedWorkstationGroupReconciler`** (AC: #1, #2, #3, #6, #7, #8) — **cœur de la story**
  - [x] Créer `app/Services/ControlHub/ImposedWorkstationGroupReconciler.php` (`declare(strict_types=1)`). Injecter `WorkstationGroupService` (chemin de création parc) par le constructeur (résolution conteneur).
  - [x] Méthode publique `reconcile(): ImposedGroupReconciliationResult` (ou un simple `array`/DTO de stats `{created, confirmed, adopted, released, errors}`) :
    - Résoudre le contrat actif : `$contract = ControlHubContract::active();`. **Si `null` → return immédiat (no-op, stats à zéro)** — garde NFR3/AC7. **Ne lit aucune autre table.**
    - Charger les groupes imposés du contrat : `$imposed = $contract->imposedGroups()->get();` (modèle `ControlHubContractImposedGroup` : `name`, `label_name` nullable — cf. 28.1).
    - **Boucle de garantie** (pour chaque groupe imposé `name` + `label_name`), chacune dans un `try/catch` + `Log` qui **n'abandonne pas** toute la boucle si un groupe échoue (patron `importFromAd()` de `WorkstationGroupService`) :
      - `$existing = WorkstationGroup::findByName($name);`
      - **Absent** ⇒ créer via `$this->workstationGroupService->createGroup([...])` avec `name`, `is_physical => false`, `is_active => true`, `managed_by_control_hub => true`, `locked => LockReason::CONTROL_HUB->value`, et `controlhub_label => $labelName ?: null`. (Tous **fillable** — cf. `WorkstationGroup::$fillable`.) → stats `created`. La création passe par `WorkstationGroupObserver::created()` qui dispatche `WorkstationGroupAdSyncJob` (AD réel).
      - **Présent** ⇒ **confirmation idempotente** par **écriture ciblée** (PAS `WorkstationGroupService::updateGroup()` qui **throw sur `isLocked()`** — même piège qu'en 30.2) :
        - Aligner `controlhub_label` sur `$labelName` (poser si vide/différent ; ne rien faire si déjà égal — idempotent).
        - `managed_by_control_hub = true`.
        - Poser `locked = control_hub` **seulement si** `locked` est vide **ou** vaut déjà `control_hub` (ne **jamais** écraser un `root` ou autre verrou plus fort).
        - `save()` dans une `DB::transaction` **uniquement si** au moins un champ a changé (`isDirty()`), pour préserver le no-op (AC #3) et éviter de réveiller l'observer `updated()` pour rien. → stats `confirmed`/`adopted`.
    - **Levée du verrou des groupes non-imposés (AC #6)** : pour chaque `WorkstationGroup` portant `locked = control_hub` (ou `managed_by_control_hub = true`) dont le `name` **n'est plus** dans la liste des groupes imposés du contrat actif → écriture ciblée : `locked = null` **si** `locked === 'control_hub'`, `managed_by_control_hub = false`. **Ne pas supprimer** le groupe. → stats `released`. (Scope : `where('managed_by_control_hub', true)->orWhere('locked', LockReason::CONTROL_HUB->value)`.)
  - [x] **R3** : aucun « central » dans le service, ses méthodes, ses messages/commentaires. Vocabulaire `ControlHub*`/`imposed`/`amont`.
  - [x] **NFR3** : la lecture du contrat n'est injectée **que** dans ce service/listener ; aucun chemin parc existant ne se met à dépendre du contrat. Sans contrat actif → retour immédiat.

- [x] **Task 2 — DTO de résultat (optionnel mais recommandé)** (AC: #1, #2, #3, #6)
  - [x] Si un retour structuré est souhaité, créer `app/Services/ControlHub/Data/ImposedGroupReconciliationResult.php` (compteurs `created`, `confirmed`, `adopted`, `released`, `errors[]`, `+ toArray()`), sur le patron de `app/Services/ControlHub/Data/ContractIngestionResult.php` (28.2). Sinon, retourner un simple `array<string,int>` documenté. **Pas** de sur-conception : un tableau de compteurs suffit si le DTO alourdit.

- [x] **Task 3 — Listener `ReconcileImposedWorkstationGroups` sur `ControlHubContractChanged`** (AC: #5, #7)
  - [x] Créer `app/Listeners/ReconcileImposedWorkstationGroups.php` : `handle(ControlHubContractChanged $event): void` → appelle `app(ImposedWorkstationGroupReconciler::class)->reconcile();`. Listener **synchrone** (l'événement est déjà dispatché **après commit** par l'ingestion ; les écritures AD sont déjà déférées en jobs queue par l'observer — pas besoin de `ShouldQueue`, qui compliquerait standalone/tests). Documenter ce choix.
  - [x] **Enregistrer** le mapping dans `app/Providers/EventServiceProvider.php` (`$listen`, `shouldDiscoverEvents()` est `false` → enregistrement explicite obligatoire) :
    `ControlHubContractChanged::class => [ReconcileImposedWorkstationGroups::class]`. **C'est le 1er listener** de cet événement (inerte jusque-là — cf. 28.2). NFR3 : sans contrat, l'événement n'est jamais émis.
  - [x] **NE PAS** modifier `ControlHubContractIngestionService` (il émet déjà l'événement après commit). Le couplage 28.2→30.3 se fait **uniquement** par l'abonnement à l'événement.

- [x] **Task 4 — Commande artisan de réconciliation manuelle** (AC: #1, #2, #3) — ops/recovery
  - [x] Créer `app/Console/Commands/ReconcileImposedWorkstationGroups.php` (signature ex. `controlhub:reconcile-imposed-groups`) qui invoque `ImposedWorkstationGroupReconciler::reconcile()` et affiche les compteurs. Permet une réconciliation **explicite/idempotente** hors réception (reprise après incident, provisioning), et offre un point d'invocation testable. **Garde NFR3** : sans contrat actif → message « aucun contrat amont actif » + exit 0, rien d'écrit.
  - [x] (Pas d'enregistrement manuel nécessaire : Laravel 11 auto-découvre les commandes de `app/Console/Commands`.)

- [x] **Task 5 — Verrou de suppression : vérifier la réutilisation (pas de nouveau code de refus)** (AC: #4)
  - [x] **Vérifier** que `WorkstationGroupService::deleteGroup()` **throw déjà** `\RuntimeException` sur `isLocked()` (lignes ~564-588) — c'est le garde-fou serveur. Un groupe imposé (`locked = control_hub`) y est donc **non supprimable** sans aucune ligne nouvelle. Ajouter un **test** prouvant le refus (Task 7), pas de nouveau code service.
  - [x] **Vérifier** l'UI `resources/views/pages/parc/groups/[id]/index.blade.php` : le bloc « Supprimer » est déjà gardé par `@if ($isLocked)` (≈ ligne 1803) → affiché désactivé avec cadenas. Confirmer qu'un groupe imposé tombe bien dans cette branche (`isLocked()` vrai car `locked = control_hub`). **Aucune** modification requise ici, sauf l'amélioration de lisibilité de la Task 6.

- [x] **Task 6 — UI : lisibilité « groupe imposé par l'amont » (FR8, léger)** (AC: #4)
  - [x] Sur la page du groupe (`resources/views/pages/parc/groups/[id]/index.blade.php` et/ou son partial d'en-tête), afficher un **badge en lecture seule** « Imposé par le contrat amont — non supprimable » lorsque le groupe est imposé (`$group->managed_by_control_hub` **et** `$group->getLockReason() === LockReason::CONTROL_HUB`). Réutiliser le style des badges/`getLockDescription()` existants. **Pas** de nouvel écran, **pas** d'action : pure lecture (le label réservé porté est déjà affiché disabled par 30.2 dans le form d'édition).
  - [x] Respecter le routing filesystem + Livewire SFC. **Pas** de méthode Livewire nommée `upload` (réservée — mémoire projet). **Pas** de modale nouvelle (la confirmation de suppression existante suffit ; elle n'est de toute façon pas atteignable sur un groupe verrouillé).

- [x] **Task 7 — Tests HÔTE (php8.4 + sqlite, `RefreshDatabase`)** (AC: #1–#8)
  - [x] `tests/Feature/ControlHub/ImposedWorkstationGroupReconcilerTest.php` — préparer l'état via factories 28.1 (`ControlHubContractFactory` actif + `ControlHubContractImposedGroupFactory`/`->withLabel()` + `ControlHubContractLabelFactory`/`->reserved()`) et `WorkstationGroupFactory`. **Seeder directement** (ne pas dépendre du transport/ingestion 28.2). `Queue::fake()` pour neutraliser/asserter `WorkstationGroupAdSyncJob` (cf. observer) — pattern 30.2.
    - **AC1** : contrat actif + imposed group absent → `reconcile()` crée le `WorkstationGroup` (`is_physical=false`, `managed_by_control_hub=true`, `locked='control_hub'`, `controlhub_label=L`). Assert `Queue::assertPushed(WorkstationGroupAdSyncJob::class)` (chemin AD réutilisé).
    - **AC2** : `WorkstationGroup` `G` pré-existant (sans label) → reconcile pose `controlhub_label`, `managed_by_control_hub=true`, `locked='control_hub'` ; `WorkstationGroup::where('name','G')->count() === 1` (pas de doublon). Variante **adopt ROOT** : groupe pré-existant `locked='root'` → `locked` **reste** `root` (non écrasé), `managed_by_control_hub=true`.
    - **AC3** : appeler `reconcile()` **deux fois** → 2e passe sans création/changement (compteurs `created=0` ; pas de doublon ; pas d'exception).
    - **AC4** : groupe imposé réconcilié → `app(WorkstationGroupService::class)->deleteGroup($g->id)` lève `\RuntimeException` ; le groupe **existe toujours** en base.
    - **AC6** : groupe `managed_by_control_hub=true` + `locked='control_hub'` dont le nom **n'est plus** imposé par le contrat actif → reconcile lève le verrou (`locked` null, `managed=false`) **sans supprimer** ; un groupe `locked='root'` non imposé n'est **pas** touché.
    - **AC7 standalone** : aucun contrat actif → `reconcile()` no-op (aucun `WorkstationGroup` créé/modifié, `Queue::assertNothingPushed()` côté AD-sync de ce service).
    - **AC8 R3** : introspection — `grep`/reflection : aucun FQCN/méthode/fichier livré ne contient `central`.
  - [x] `tests/Feature/ControlHub/ReconcileImposedGroupsListenerTest.php` (ou intégration via l'ingestion) : ingérer un payload `imposed_groups` via `ControlHubContractIngestionService::ingest()` → l'événement `ControlHubContractChanged` (émis après commit) **déclenche** le listener → les `WorkstationGroup` imposés existent ensuite (AC #5). `Queue::fake()`. (Ne pas `Event::fake()` ici — on veut que le listener s'exécute réellement.)
  - [x] Test commande : `$this->artisan('controlhub:reconcile-imposed-groups')->assertExitCode(0)` avec contrat actif → groupes créés ; sans contrat → message standalone, exit 0, rien créé.
  - [x] **Non-régression** : `--filter ControlHubContract` (suites 28.x), `--filter WorkstationGroupLabel` (30.2), et suite pages parc / `WorkstationGroup`. Le **listener nouvellement branché** s'exécute désormais à chaque ingestion mutante : vérifier que les tests d'ingestion 28.2 restent verts (ils n'imposent pas de groupes ⇒ reconcile no-op ; sinon `Queue::fake()` neutralise l'AD).
  - [x] **Pièges SQLite** : ne pas tester de longueur `varchar` ni d'unicité sur `NULL` ; l'idempotence/le verrou sont testés **par comportement**, pas par contrainte DB (mémoire `sqlite_tests_no_varchar_enforcement`). Observer AD : `Queue::fake()` (ou `WorkstationGroupObserver::disableSync()` au besoin) — pas de LDAP réel en test HÔTE.

- [x] **Task 8 — Doc QA (append-only)** (AC: tous)
  - [x] **Enrichir** `docs/qa/domains/controlhub-contract.md` par une **nouvelle section** « Story 30.3 — Garantie d'existence des groupes imposés » (append ; ne jamais créer de fichier QA par story). Décrire : réconciliation (créer si absent / confirmer sans doublon / lever le verrou si non-imposé), verrou `locked=control_hub` réutilisant `deleteGroup()`/UI, déclenchement via listener sur `ControlHubContractChanged`, commande `controlhub:reconcile-imposed-groups`, comportement standalone (NFR3), idempotence (NFR4), points de vérification e2e (création réelle du parc en `OU=Parcs` via `WorkstationGroupAdSyncJob`).
  - [x] Mettre à jour l'entrée `docs/qa/README.md` du domaine `controlhub-contract` (append au libellé existant, mentionner Story 30.3).

- [x] **Task 9 — Validation finale**
  - [x] `php artisan test --filter Imposed` (HÔTE) → vert ; `--filter ReconcileImposedGroups` → vert.
  - [x] Non-régression : `--filter ControlHubContract` + `--filter WorkstationGroupLabel` + suite pages parc / `WorkstationGroup` (notamment `deleteGroup`).
  - [x] `grep -riE central` sur les fichiers livrés → vide. Vérifier qu'**aucune migration** n'a été ajoutée (story SQL-free) et qu'aucun chemin parc existant n'a été modifié au-delà de la lisibilité (Task 6).

### Périmètre — ce qui EST / N'EST PAS dans 30.3

**DANS** : service `ImposedWorkstationGroupReconciler` (créer si absent / confirmer sans doublon / aligner label + flags / lever le verrou si non-imposé) ; listener `ReconcileImposedWorkstationGroups` sur `ControlHubContractChanged` + enregistrement `EventServiceProvider` ; commande artisan de réconciliation manuelle ; verrou de suppression **par réutilisation** de `locked=control_hub` (service `deleteGroup()` + UI déjà gardés) ; badge UI « imposé par l'amont » (lecture seule) ; tests HÔTE + doc QA append. **Aucune migration.**

**HORS** (ne pas déborder — chaque point a sa story) :
- **Mapping refnum d'un label *libre*** (`assignLabel`/`detachLabel`, sélecteur de label dans le form de parc) → **déjà livré en 30.2**. 30.3 **pose** un label *réservé* sur un groupe imposé, mais ne re-livre pas le service de mapping libre.
- **Résolution `StateCompiler` d'un item `label:<nom>`** (propagation à tous les groupes portant le label, règle verrou/permissif) → **Story 30.4**. 30.3 garantit l'existence des groupes ; elle ne résout **rien** côté compilation d'état.
- **Validation prédictive de collision insoluble** → **Story 30.5**.
- **Rupture/release du lien** (suppression/déverrouillage massif à la perte du contrat amont) → **Epic 32**. 30.3 lève le verrou d'un groupe **individuellement non-imposé** par le contrat **actif**, mais ne traite pas la disparition du contrat lui-même.
- **Ingestion / transport** des groupes imposés → **Epic 28** (28.1 schéma + 28.2 ingestion). 30.3 **consomme** `controlhub_contract_imposed_groups` ; ne ré-ingère rien et **ne modifie pas** `ControlHubContractIngestionService`.

### Décision de design — pas de migration, réutilisation maximale

| Besoin 30.3 | Brique existante réutilisée | Pourquoi pas du neuf |
|---|---|---|
| Porter le label réservé sur le groupe | colonne `controlhub_label` (30.2, par NOM) | déjà livrée, robuste prune/recreate ; `hasControlHubLabel()`/`controlHubLabel()` dispo |
| Marquer « imposé » | `managed_by_control_hub` (boolean, schéma unifié) + scope `managedByControlHub()` | colonne + scope déjà présents |
| Verrou de suppression | `locked = LockReason::CONTROL_HUB` ; `deleteGroup()` throw sur `isLocked()` ; UI désactive le bouton | mécanisme de verrou éprouvé (root) — zéro nouveau code de refus |
| Création réelle en AD | `WorkstationGroupService::createGroup()` → `WorkstationGroupObserver::created()` → `WorkstationGroupAdSyncJob` | l'imposé doit exister dans `OU=Parcs` comme tout parc ; pas de chemin SQL-only |
| Déclenchement | `ControlHubContractChanged` (28.2, **sans listener** jusqu'ici) | l'event était prévu pour ça (« Story 28.3+ pourra s'y abonner ») |

⇒ **Aucune colonne, aucune table, aucune migration.** Le seul net-new = un service de réconciliation, un listener, une commande, un badge UI, des tests, de la doc.

### Code réel à réutiliser (ancrage exact — ne rien réinventer)

- **Groupes imposés (lecture)** : `app/Models/ControlHubContractImposedGroup.php` — champs réels **`name`** (nom du WG à garantir) + **`label_name`** (nullable, label réservé associé), relation `contract()`. Lus via `ControlHubContract::active()->imposedGroups()`.
- **Contrat actif** : `app/Models/ControlHubContract.php::active()` (30.2) — singleton `link_state=active`, **identique** à `ControlHubContractIngestionService::resolveActiveContract()`. `null` = standalone.
- **Chemin de création parc + AD** : `app/Services/Parc/WorkstationGroupService::createGroup(array $data)` (transaction + log ; observer `created()` dispatche `WorkstationGroupAdSyncJob`). ⚠️ `updateGroup()`/`deleteGroup()` **throw sur `isLocked()`** : ne **pas** y router l'écriture/confirmation d'un groupe imposé (écriture ciblée directe pour la confirmation, comme 30.2).
- **Modèle WG** : `app/Models/WorkstationGroup.php` — `$fillable` inclut `managed_by_control_hub`, `controlhub_label`, `locked`, `is_physical`, `is_active` ; helpers `isLocked()`, `getLockReason()`, `lock()/unlock()`, `findByName()`, `hasControlHubLabel()`, scope `managedByControlHub()`.
- **Enum verrou** : `app/Enums/LockReason.php` — `CONTROL_HUB = 'control_hub'` (description « Géré par ControlHub »). **Pré-existant et conforme R3** — réutiliser tel quel.
- **Observer/sync AD** : `app/Observers/WorkstationGroupObserver.php` (`created()` → `WorkstationGroupAdSyncJob::create()`), `disableSync()/enableSync()` pour tests.
- **Événement** : `app/Events/ControlHubContractChanged.php` (dispatché **après commit** par l'ingestion 28.2, sur mutation seulement — jamais sur no-op).
- **Enregistrement listener** : `app/Providers/EventServiceProvider.php` (`$listen`, `shouldDiscoverEvents()=false`).
- **DTO de résultat (patron)** : `app/Services/ControlHub/Data/ContractIngestionResult.php`.
- **Patron commande** : commandes de `app/Console/Commands/` (auto-découverte Laravel 11).
- **Patron service ControlHub** : `app/Services/ControlHub/ControlHubContractIngestionService.php` (transaction, log, normalisation, R3).
- **UI groupe** : `resources/views/pages/parc/groups/[id]/index.blade.php` — `deleteGroup()` (Gate `delete-workstationGroup` + service), bloc « Supprimer » gardé `@if ($isLocked)` (≈ l.1803), `getLockDescription()`.
- **Factories** : `database/factories/ControlHubContractFactory.php`, `ControlHubContractImposedGroupFactory.php` (`->withLabel($name)`), `ControlHubContractLabelFactory.php` (`->reserved()`), `WorkstationGroupFactory.php`.

### Garde-fous projet CRITIQUES (contraintes de la story)

- **R3 — Vocabulaire (BLOQUANT)** : **INTERDIT** — « central » dans tout nom de classe, méthode, propriété, commande, message ou commentaire livré. Vocabulaire : « amont » / `ControlHub*` / `imposed` / `label`. La valeur d'enum **pré-existante** `control_hub` est conforme. [mémoires `project_contrat_manage_se5_upstream`, `legacy_central_vs_local_split` ; prd#R3]
- **NFR3 — Standalone préservé** : sans contrat amont actif, la réconciliation est un **no-op total** ; l'événement n'est jamais émis ; aucun chemin parc existant ne se met à dépendre du contrat. La lecture du contrat est confinée au service/listener/commande de 30.3. [prd#NFR3]
- **NFR4 — Idempotence** : réception/réconciliation répétée = no-op (pas de doublon, pas d'écriture si `!isDirty()`). [prd#NFR4]
- **Réutiliser le moteur de verrou existant** : la « non-suppressibilité » = `locked=control_hub` + `deleteGroup()` throw + UI désactivée. **Ne pas** introduire de nouveau Gate ni de logique de refus parallèle. [Additional Requirements epics#48]
- **Propriété par-parc = page du groupe** : le badge « imposé » se lit sur la page du groupe, pas dans un écran séparé. [mémoire `feedback_per_group_property_belongs_on_group_pages`]
- **Livewire** : ne jamais nommer une action `upload` (réservée). [mémoire `project_livewire_reserved_upload_method`]
- **Pas de question sur-conçue** : règle dérivable (verrou via `locked`, trigger via event) → l'appliquer, pas de tableau d'options. [mémoire `feedback_no_overengineered_choices`]

### Project Structure Notes

- **Nouveaux fichiers** :
  - `app/Services/ControlHub/ImposedWorkstationGroupReconciler.php`
  - `app/Listeners/ReconcileImposedWorkstationGroups.php`
  - `app/Console/Commands/ReconcileImposedWorkstationGroups.php`
  - `app/Services/ControlHub/Data/ImposedGroupReconciliationResult.php` (optionnel — sinon `array` de compteurs)
  - `tests/Feature/ControlHub/ImposedWorkstationGroupReconcilerTest.php`
  - `tests/Feature/ControlHub/ReconcileImposedGroupsListenerTest.php`
- **Fichiers modifiés** :
  - `app/Providers/EventServiceProvider.php` (mapping `ControlHubContractChanged` → listener)
  - `resources/views/pages/parc/groups/[id]/index.blade.php` (badge « imposé par l'amont » lecture seule — Task 6)
  - `docs/qa/domains/controlhub-contract.md` (append Story 30.3), `docs/qa/README.md` (append)
- **AUCUNE migration** (colonnes `locked`, `managed_by_control_hub`, `controlhub_label` déjà présentes).
- **Racine = projet Laravel** (artisan/app à la racine ; pas de préfixe `laravel/`). [mémoire `root_is_laravel`]
- **Tests sur HÔTE** (php8.4 + `pdo_sqlite`), jamais la VM ; ne pas interagir avec la VM depuis ce worktree. [mémoires `phpunit_test_env_host_vs_vm`, `feedback_worktree_no_vm_sync`]

### Pièges identifiés

1. **Ne pas confirmer un groupe imposé existant via `WorkstationGroupService::updateGroup()`** (throw sur `isLocked()` — un groupe déjà `locked` ne pourrait plus être confirmé). Écriture **ciblée** sur les colonnes (`controlhub_label`, `managed_by_control_hub`, `locked`) dans une transaction, seulement si `isDirty()`.
2. **Ne pas écraser un verrou plus fort** : poser `locked=control_hub` **uniquement** si `locked` est vide ou déjà `control_hub`. Un `root` doit survivre. Symétriquement, à la levée (AC #6), ne lever que si `locked === 'control_hub'`.
3. **No-op idempotent** : ne `save()` que si un champ change. Sinon l'observer `updated()` se déclenche (dispatch AD inutile) et l'idempotence AC #3 casse.
4. **AD réel à la création** : la création passe par l'observer (`WorkstationGroupAdSyncJob`). En **test HÔTE**, `Queue::fake()` (ou `disableSync()`). En prod VM, la synchro AD écrit dans `OU=Parcs` (groupe logique) — d'où `is_physical=false`. Ne pas créer un chemin SQL-only.
5. **Listener = 1er consommateur de `ControlHubContractChanged`** : vérifier la non-régression des tests 28.2 (l'event devient « actif »). Sans groupe imposé dans le payload → reconcile no-op ; sinon `Queue::fake()`.
6. **Levée du verrou ≠ suppression** : 30.3 lève seulement le verrou des groupes non-imposés (le refnum décide ensuite de supprimer). La suppression/déverrouillage de masse à la **rupture du lien** est Epic 32 — ne pas l'implémenter ici.
7. **Label réservé « dangling »** : si un groupe garde un `controlhub_label` réservé après dé-imposition, le laisser tel quel (sans effet tant qu'aucun item `label:<nom>` ne le cible — 30.4). Ne pas tenter de le nettoyer en 30.3.
8. **R3** : « ControlHub »/`control_hub` OK ; « central » interdit. Vérifier messages de commande + doc.

### References

- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 30.3] — AC d'origine (créer si absent avec label réservé ; confirmer sans duplication ; refnum ne peut supprimer tant que le contrat l'exige — FR11).
- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Epic 30] — labels & ciblage sous-instance (FR9–FR13).
- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#NonFunctional Requirements] — NFR3 (standalone), NFR4 (idempotence), Additional Requirements (réutiliser l'existant, R3).
- [Source: _bmad-output/implementation-artifacts/30-2-mapping-label-refnum.md] — colonne `controlhub_label` (par nom, sans FK dure), helpers/scope WG, `ControlHubContract::active()`, piège `isLocked()` de `updateGroup()`, patron service ControlHub, tests HÔTE + `Queue::fake()`.
- [Source: _bmad-output/implementation-artifacts/28-1-modele-et-persistance-du-contrat-amont.md] — `controlhub_contract_imposed_groups` (`name`, `label_name`), modèle `ControlHubContractImposedGroup`.
- [Source: _bmad-output/implementation-artifacts/28-2-reception-idempotente-contrat-amont.md] — ingestion idempotente, `ControlHubContractChanged` émis après commit sur mutation (sans listener jusqu'ici).
- [Source: app/Models/ControlHubContractImposedGroup.php] — champs réels (`name`, `label_name`), relation `contract()`.
- [Source: app/Services/Parc/WorkstationGroupService.php#createGroup,#deleteGroup,#updateGroup] — chemin création parc (+ observer AD) ; `delete/update` throw sur `isLocked()`.
- [Source: app/Observers/WorkstationGroupObserver.php] — `created()` dispatche `WorkstationGroupAdSyncJob` ; `disableSync()`.
- [Source: app/Enums/LockReason.php] — `CONTROL_HUB = 'control_hub'`.
- [Source: app/Events/ControlHubContractChanged.php] — événement de mutation du contrat (point d'abonnement de 30.3).
- [Source: app/Providers/EventServiceProvider.php] — `$listen`, `shouldDiscoverEvents()=false`.
- [Source: resources/views/pages/parc/groups/[id]/index.blade.php] — `deleteGroup()` + bloc « Supprimer » gardé `@if ($isLocked)`.
- [Source: app/Services/ControlHub/Data/ContractIngestionResult.php] — patron DTO de résultat.

## Dépendances

- **Amont (bloquantes) — toutes satisfaites** :
  - **Story 28.1 (modèle/persistance)** — **LIVRÉ, commité sur `main`** : `controlhub_contract_imposed_groups` (`name`, `label_name`), `controlhub_contract_labels` (`mode` free/reserved), modèles + factories. **Fondation directe.**
  - **Story 30.2 (mapping label refnum)** — colonne `controlhub_label` (par nom) + helpers WG + `ControlHubContract::active()`. **Statut `review`** (committée/stagée). **Non bloquante pour le dev** : les colonnes/helpers réutilisés par 30.3 sont présents sur `main`/staging ; les tests 30.3 seedent directement (factories 28.1) et n'exécutent pas le transport.
  - **Colonnes `managed_by_control_hub` + `locked`** — présentes (schéma unifié + migration `2026_02_05_160948`). **Aucune migration à créer.**
- **Amont (non bloquantes)** :
  - **Story 28.2 (ingestion idempotente)** — **`review`**. **Non bloquante** : 30.3 s'abonne à l'événement `ControlHubContractChanged` (déjà émis par 28.2) mais teste la réconciliation en seedant directement. En production, c'est l'ingestion 28.2 qui peuple `imposed_groups` et déclenche l'event.
- **Aval (dépendent de 30.3)** :
  - **30.4** — résolution `StateCompiler` par label : les groupes imposés (et leur `controlhub_label` réservé) doivent exister pour qu'un item `label:<nom>` se propage.
  - **Epic 32** — rupture du lien : release/déverrouillage de masse s'appuiera sur le verrou `control_hub` posé ici.

## Testing

- **Cible d'exécution : HÔTE** (php8.4 + `pdo_sqlite`), **jamais la VM**. [mémoire `phpunit_test_env_host_vs_vm`]
- `DB_CONNECTION=sqlite` (cf. `phpunit.xml`), trait `RefreshDatabase`. `Queue::fake()` pour neutraliser/asserter `WorkstationGroupAdSyncJob` (LDAP indisponible en HÔTE).
- Filtres ciblés : `--filter Imposed`, `--filter ReconcileImposedGroups` ; non-régression : `--filter ControlHubContract`, `--filter WorkstationGroupLabel`, suite pages parc / `WorkstationGroup` (dont `deleteGroup`).
- Couverture : création (absent), confirmation idempotente (existant, pas de doublon), adopt ROOT (verrou non écrasé), idempotence (2 passes), verrou de suppression (`deleteGroup` throw + groupe persiste), levée du verrou (non-imposé → `locked` null sans suppression), déclenchement par listener (ingestion → groupes créés), commande artisan (avec/sans contrat), standalone (no-op), R3 (introspection).
- **Pièges SQLite** : idempotence/verrou testés **par comportement**, pas par contrainte DB ; ne pas tester `varchar`/unicité sur `NULL`. [mémoire `sqlite_tests_no_varchar_enforcement`]
- ⚠️ **VM** : migrations **pas auto-jouées** par le dev-cycle — sans objet ici (aucune migration), mais ne pas présumer l'état AD côté VM. [mémoire `vm_migrations_not_auto_applied`]

## Recommandation Modèle Dev

**`opus`.**

Justification : story **backend à invariants denses** malgré l'absence de migration et une UI minimale.
1. **Réconciliation « désir d'état » idempotente** : créer/confirmer/lever sans doublon, écriture conditionnelle `isDirty()`, no-op standalone — exactement le type de logique où un dev pressé introduit des doublons ou casse l'idempotence (réveil parasite de l'observer).
2. **Verrou de suppression par réutilisation** : poser/lever `locked=control_hub` **sans écraser** `root`, en s'appuyant sur `deleteGroup()` existant — raisonnement de précédence de verrou facile à rater.
3. **Intégration AD/LDAP** : passer par `createGroup()`/observer (et non un chemin SQL-only) pour que le parc imposé existe réellement dans `OU=Parcs` ; piège `isLocked()` de `updateGroup()` à contourner pour la confirmation.
4. **Câblage événementiel** : 1er listener de `ControlHubContractChanged` (effet de bord sur toute ingestion 28.2 mutante) + garde-fous transverses simultanés (R3, NFR3 standalone, NFR4 idempotence).
Le dev-cycle routera la **review vers le modèle opposé** (sonnet/fable) ; placer **opus** sur l'implémentation met le raisonnement là où le risque d'invariant (idempotence, précédence de verrou, effet de bord listener) est maximal.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m]

### Debug Log References

- Tests HÔTE exécutés via `CACHE_DRIVER=array vendor/bin/phpunit` (l'hôte n'a pas l'extension `apcu` ; le défaut `config/cache.php` est `apc` → boot artisan échoue sans override ; `vendor/bin/phpunit` lit `phpunit.xml`). Aucune interaction VM (worktree).

### Completion Notes List

- **Net-new livré (8 AC / 9 tâches)** — AUCUNE migration (colonnes `locked`, `managed_by_control_hub`, `controlhub_label` confirmées présentes : fillable + casts du modèle `WorkstationGroup`).
  1. `ImposedWorkstationGroupReconciler` (service cœur) — `reconcile(): ImposedGroupReconciliationResult`. NFR3 : `ControlHubContract::active() === null` → return immédiat (aucune autre table lue). Boucle de garantie avec `try/catch` + `Log::error` par groupe (n'abandonne pas la boucle). Création via `WorkstationGroupService::createGroup([... is_physical=false, is_active=true, managed_by_control_hub=true, locked=control_hub, controlhub_label=label?:null])`. Confirmation par écriture CIBLÉE (jamais `updateGroup()`), `save()` dans `DB::transaction` UNIQUEMENT si `isDirty()` (NFR4). Verrou posé seulement si vide ou déjà `control_hub` (jamais d'écrasement de `root` → compteur `adopted`). Levée du verrou (AC6) : scope `where(managed_by_control_hub)->orWhere(locked=control_hub)`, `locked=null` SI `=== control_hub`, `managed_by_control_hub=false`, sans suppression ; label dangling laissé tel quel.
  2. `ImposedGroupReconciliationResult` (DTO compteurs created/confirmed/adopted/released/errors + `toArray()`), patron `ContractIngestionResult`.
  3. Listener `ReconcileImposedWorkstationGroups` (synchrone, pas de `ShouldQueue` — documenté) → `reconcile()`. Enregistré explicitement dans `EventServiceProvider::$listen` (`shouldDiscoverEvents()=false`). 1er consommateur de `ControlHubContractChanged`. `ControlHubContractIngestionService` NON modifié.
  4. Commande artisan `controlhub:reconcile-imposed-groups` (auto-découverte L11) — sans contrat actif : message standalone + exit 0, rien écrit.
  5. Verrou de suppression = RÉUTILISATION : vérifié `WorkstationGroupService::deleteGroup()` throw déjà `\RuntimeException` sur `isLocked()` (l.572-574) + UI bloc « Supprimer » déjà gardé `@if ($isLocked)` (l.1803). ZÉRO nouveau code de refus.
  6. UI : badge lecture seule « Imposé par le contrat amont — non supprimable » sur la fiche du groupe (`index.blade.php`, zone badges), condition `managed_by_control_hub && getLockReason() === LockReason::CONTROL_HUB`.
- **Résultats tests HÔTE (php8.4 + pdo_sqlite)** :
  - `--filter Imposed` → **20/20 verts** (104 assertions) : `ImposedWorkstationGroupReconcilerTest` (16) + `ReconcileImposedGroupsListenerTest` (4, contient « Imposed »).
  - `--filter ReconcileImposedGroups` → **4/4 verts** (12 assertions).
  - Non-régression `--filter ControlHubContract` → **48/48** (246 assert) ; `--filter WorkstationGroupLabel` → **19/19** (69 assert) ; dossier complet `tests/Feature/ControlHub/` → **73/73** (350 assert). Le listener nouvellement branché n'a cassé aucun test d'ingestion 28.2 (event devenu actif ; `QUEUE_CONNECTION=sync` → job AdSync d'un groupe logique bénin en test).
  - R3 : `grep -riE central` sur les fichiers livrés → seules occurrences = commentaires garde-fou « aucun mot central » ; test d'introspection `r3_no_delivered_identifier_contains_central` vert (FQCN/méthodes/propriétés propres).
- **Déviation mineure** : 1er run du test standalone échouait car la création du fixture `WorkstationGroup` (factory) déclenche l'observer → push un `WorkstationGroupAdSyncJob` avant `reconcile()` ; corrigé en ré-appelant `Queue::fake()` après le fixture pour n'observer que les jobs poussés par `reconcile()` (le service en standalone ne pousse rien).
- **Échecs PRÉ-EXISTANTS non imputables** (déjà documentés dans le record 30.2, git stash) :
  - `tests/Unit/Models/WorkstationGroupEnvironmentCastTest` (3 erreurs) : `ldap_search(): Can't contact LDAP server` — ces tests ne `Queue::fake()` pas (`QUEUE=sync`) et joignent un LDAP absent ; sans rapport avec 30.3.
  - `tests/Feature/Livewire/Parc/GroupShowPageTest` : `SQLSTATE no such column: printer_workstation_group.is_default` (migration `is_default` non jouée en SQLite mémoire) déclenché par `_partials/machines-list.blade.php` + « did not remove its own error handlers » ; sans rapport avec le badge ajouté dans `index.blade.php`.
- **Garde-fous tenus** : R3 (aucun identifiant « central » ; `control_hub`/`ControlHub` conformes), NFR3 (standalone no-op total : service + listener + commande, contrat severed = inactif), NFR4 (idempotence par `isDirty()` ; 2 passes = no-op), précédence de verrou (`root` jamais écrasé, levée seulement si `control_hub`), réutilisation du moteur de verrou existant (zéro nouveau Gate). Aucune migration ; aucun chemin parc existant modifié hors badge UI lecture seule.

#### Corrections post-review (2026-06-28)

Application des 7 corrections (C1–C7) issues de la code review adversariale :

- **C1 (P1+M1) — Robustesse listener & levée de verrou** : `ReconcileImposedWorkstationGroups::handle()` enveloppe désormais `reconcile()` dans un `try/catch(\Throwable)` + `Log::error` — la réconciliation ne peut JAMAIS faire échouer une ingestion déjà committée (event dispatché après commit). `ImposedWorkstationGroupReconciler::releaseDeImposedGroups()` : `try/catch(\Throwable)` PAR GROUPE (symétrique à la boucle principale) → `errors[] = "Groupe non-imposé '{nom}': {msg}"` + `Log::error`, sans interrompre la boucle.
- **C2 (P3+P5) — Exit code commande + race check-then-act** : boucle de création — catch ciblé `\InvalidArgumentException | \Illuminate\Database\QueryException` AVANT le `\Throwable` générique (l'exception de nom dupliqué de `validateGroupData()` est une `\InvalidArgumentException`). Re-vérification `findByName()` : si le groupe existe désormais (création concurrente réussie) → `confirmed++` (confirmation idempotente, PAS `errors`) ; sinon erreur normale. Commande artisan : `return self::FAILURE` si `errors !== []`, sinon `self::SUCCESS` (standalone sans contrat reste exit 0).
- **C3 (P8) — Normalisation `label_name`** : variable `$labelName` normalisée en tête de boucle (`'' → null`) et réutilisée dans les DEUX chemins (création + confirmation) ; suppression du `?: null` divergent dans `createImposedGroup()`.
- **C4 (P4) — Badge UI** : condition du badge « Imposé par le contrat amont — non supprimable » élargie à `$group->managed_by_control_hub` seul (couvre le cas adopted root-locked, AC4).
- **C5 (P7) — Test R3 sur littéraux** : test R3 complété — pour chaque FQCN livré, `assertStringNotContainsStringIgnoringCase('central', file_get_contents(ReflectionClass::getFileName()))`. A nécessité de reformuler les commentaires « garde-fou » des 4 fichiers livrés (qui contenaient eux-mêmes le mot littéral interdit) en « terme prohibé proscrit ».
- **C6 (M2) — Test release via listener** : nouveau test `re_ingesting_without_a_previously_imposed_group_releases_its_lock_via_the_listener` — ingestion d'un groupe imposé G (créé/verrouillé), puis ré-ingestion sans G (prune) → event → listener → verrou levé (`locked=null`, `managed_by_control_hub=false`) SANS suppression.
- **C7 (P6) — Assertions compteurs adopt ROOT** : `confirming_a_root_locked_group_does_not_override_the_lock` complété avec `assertSame(0, created)`, `assertSame(1, confirmed)`, `assertSame(0, released)` (vérifié dans le code : une adoption incrémente `confirmed` ET `adopted`).
- **NON corrigés (cosmétique, anti-sur-conception)** : P2 (double `active()` commande), M3 (sémantique compteur `released`).
- **Résultats tests post-corrections (HÔTE, `CACHE_DRIVER=array`)** : `--filter Imposed` → **21/21 verts** (121 assertions) ; `--filter ReconcileImposedGroups` → **5/5** (18 assertions) ; non-régression `--filter ControlHubContract` → **48/48** (246) ; `--filter WorkstationGroupLabel` → **19/19** (69). `grep -riE central` sur les 4 fichiers livrés → **vide** (exit 1). Échecs pré-existants non imputables inchangés (WorkstationGroupEnvironmentCastTest LDAP, GroupShowPageTest is_default).

### File List

**Créés :**
- `app/Services/ControlHub/ImposedWorkstationGroupReconciler.php`
- `app/Services/ControlHub/Data/ImposedGroupReconciliationResult.php`
- `app/Listeners/ReconcileImposedWorkstationGroups.php`
- `app/Console/Commands/ReconcileImposedWorkstationGroups.php`
- `tests/Feature/ControlHub/ImposedWorkstationGroupReconcilerTest.php`
- `tests/Feature/ControlHub/ReconcileImposedGroupsListenerTest.php`

**Modifiés :**
- `app/Providers/EventServiceProvider.php` (mapping `ControlHubContractChanged` → `ReconcileImposedWorkstationGroups`)
- `resources/views/pages/parc/groups/[id]/index.blade.php` (badge « imposé par le contrat amont » lecture seule)
- `docs/qa/domains/controlhub-contract.md` (append Section 7 — Story 30.3, scénarios 7.1–7.8 + checklist)
- `docs/qa/README.md` (append libellé domaine controlhub-contract — Story 30.3)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (statut 30-3 → review)
