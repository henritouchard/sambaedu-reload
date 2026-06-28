# Story 30.2: Mapping d'un label par le refnum

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **refnum** (administrateur de l'instance SE5),
I want **assigner un label « libre » du contrat amont à un `WorkstationGroup` existant — ou créer un groupe portant ce label — avec la garantie d'au plus 1 label par groupe et le refus des labels « réservés »**,
so that **je rattache le vocabulaire de ciblage défini en amont à mes parcs concrets, sans pouvoir détourner un label que l'autorité amont s'est réservé** (FR10).

> Story **2/5** de l'Epic 30 (« Cibler par labels — types de parc »). Elle livre **uniquement** le **mapping refnum** `WorkstationGroup ↔ label libre` + sa **validation** (1 max ; réservé non attribuable) et l'**UI** côté pages de parc. Elle ne touche **ni** `StateCompiler` (résolution par label → 30.4), **ni** la garantie d'existence des groupes imposés (→ 30.3), **ni** la validation prédictive de collision (→ 30.5).

> **Décision de design clé (à respecter)** : le lien WG↔label est porté par une **colonne string nullable `controlhub_label`** sur `workstation_groups` (le **nom** du label), **pas** une FK dure vers `controlhub_contract_labels`. Justification détaillée en Dev Notes (précédent 28.1 `imposed_groups.label_name`, robustesse à la réconciliation/rupture du lien, résolution-par-nom de 30.4). « 1 label max » = colonne simple nullable (garantie structurelle).

## Acceptance Criteria

1. **AC1 — Migration du lien WG↔label.**
   **Given** la table `workstation_groups` sans colonne de label de contrat,
   **When** la migration de la story est jouée,
   **Then** une colonne **`controlhub_label`** (`string`, **nullable**, indexée) est ajoutée — destinée à porter le **nom** d'un label de contrat amont (au plus un par groupe) — avec un commentaire de colonne explicite ; `down()` la retire proprement. **R3** : aucun nom contenant « central ».

2. **AC2 — Assignation d'un label libre (groupe existant).**
   **Given** un label en mode **`free`** (libre) du contrat amont **actif**,
   **When** le refnum l'assigne à un `WorkstationGroup` qui ne porte encore aucun label,
   **Then** `controlhub_label` du groupe vaut le **nom** du label, l'opération réussit, et un toast de succès est affiché.

3. **AC3 — Création d'un groupe portant un label libre.**
   **Given** un label en mode **`free`**,
   **When** le refnum crée un nouveau `WorkstationGroup` en lui rattachant ce label,
   **Then** le groupe est créé avec `controlhub_label` = nom du label (chemin de création parc existant réutilisé, pas un chemin parallèle).

4. **AC4 — Invariant « 1 label max par groupe ».**
   **Given** un `WorkstationGroup` portant déjà un label `A`,
   **When** le refnum tente de lui assigner un **second** label `B` (différent),
   **Then** l'opération est **refusée** au niveau **service** (exception de domaine) — le groupe conserve `A`, aucune écriture.
   **And** ré-assigner le **même** label `A` (ou détacher puis ré-assigner) reste **idempotent** / autorisé (pas d'erreur).

5. **AC5 — Label réservé non attribuable.**
   **Given** un label en mode **`reserved`** (réservé à l'autorité amont),
   **When** le refnum tente de l'assigner à un groupe (ou de créer un groupe le portant),
   **Then** l'opération est **refusée** explicitement (exception de domaine + message clair « label réservé à l'autorité amont, non attribuable ») — la base reste inchangée.

6. **AC6 — Label inconnu / hors contrat actif refusé.**
   **Given** un nom de label qui n'existe **pas** parmi les labels du contrat amont **actif** (`link_state = active`),
   **When** le refnum tente de l'assigner,
   **Then** l'opération est **refusée** (exception de domaine) : on n'assigne que des labels réellement déclarés en amont.

7. **AC7 — Détachement.**
   **Given** un `WorkstationGroup` portant un label libre,
   **When** le refnum détache le label (sélection « aucun »),
   **Then** `controlhub_label` repasse à `null`, sans erreur. (Le détachement d'un label **réservé** porté par un **groupe imposé** relève de 30.3 — hors scope ici ; en 30.2 le refnum ne manipule que des labels libres sur ses propres groupes.)

8. **AC8 — Autorisation (Gate scopé).**
   **Given** un utilisateur **sans** droit de modification sur le `WorkstationGroup` cible,
   **When** il tente d'assigner/détacher un label,
   **Then** l'action est **refusée** par le Gate `update-workstationGroup` (délégation scopée respectée), avant toute écriture.

9. **AC9 — UI refnum dans les pages de parc.**
   **Given** la page d'édition d'un `WorkstationGroup` (et le formulaire de création),
   **When** le refnum l'ouvre,
   **Then** un sélecteur **« Label de contrat amont »** propose : « Aucun » + la liste des labels **libres** du contrat actif (le label réservé actuellement porté, le cas échéant, est affiché **en lecture seule / désactivé**, jamais sélectionnable). En l'absence de contrat actif, la section est masquée ou affiche « Aucun contrat amont actif » (standalone préservé, **NFR3**).

10. **AC10 — Standalone & R3.**
    **Given** une installation SE5 **sans** contrat amont actif,
    **When** le refnum gère ses parcs,
    **Then** le comportement est **strictement inchangé** (aucun label proposé, aucune contrainte ajoutée — **NFR3**) ; **And** aucun identifiant livré (colonne, méthode, classe, exception, propriété, message) ne contient le mot **« central »** (**R3**).

## Tasks / Subtasks

- [x] **Task 1 — Migration : colonne `controlhub_label` sur `workstation_groups`** (AC: #1, #10)
  - [x] Fichier `database/migrations/2026_06_27_100000_add_controlhub_label_to_workstation_groups.php` (timestamp > `2026_06_26_100000`).
  - [x] Garde idempotente : `if (Schema::hasColumn('workstation_groups','controlhub_label')) { return; }`.
  - [x] `up()` : `$table->string('controlhub_label')->nullable()->after('controlhub_version')->comment("Nom du label de contrat amont (free) rattaché à ce parc — au plus 1 par groupe ; rattachement par nom, pas de FK dure (cf. 28.1 imposed_groups.label_name).");` + `$table->index('controlhub_label')` (support de la résolution-par-nom de 30.4 ; nom d'index court si besoin < 63 car. PG).
  - [x] `down()` : `dropIndex` puis `dropColumn('controlhub_label')`.
  - [x] **R3** : aucun « central » dans le nom de colonne/index/commentaire. **NFR3** : pas de valeur par défaut métier (nullable, null = aucun label).

- [x] **Task 2 — Modèle `WorkstationGroup` : exposer le label** (AC: #2, #4, #7)
  - [x] Ajouter `'controlhub_label'` à `$fillable` (cf. `app/Models/WorkstationGroup.php` ligne ~61). Pas de cast spécial (string nullable). PHPDoc `@property string|null $controlhub_label`.
  - [x] Helper de lecture `hasControlHubLabel(): bool` et `controlHubLabel(): ?string` (style des helpers existants `isLocked()` / `hasAppProfile()`).
  - [x] Scope `scopeCarryingControlHubLabel(Builder $q, string $name): Builder` (`where('controlhub_label', $name)`) — **réutilisé par 30.4** pour résoudre « tous les groupes portant ce label ». Le livrer ici évite la réinvention en aval.
  - [x] **NE PAS** ajouter de relation Eloquent dure vers `ControlHubContractLabel` (rattachement par nom — cf. Dev Notes / précédent 28.1).

- [x] **Task 3 — Helper « contrat amont actif » + labels assignables** (AC: #5, #6, #9, #10)
  - [x] Ajouter à `app/Models/ControlHubContract.php` un scope/méthode statique **`active(): ?self`** : `static::query()->where('link_state', ControlHubLinkState::Active)->first()` (singleton — **même résolution que `ControlHubContractIngestionService::resolveActiveContract()` de 28.2** ; réutiliser, ne pas diverger). PHPDoc rappelant le singleton « ≤1 contrat actif ».
  - [x] Pas de nouvelle table : les labels viennent de `controlhub_contract_labels` (28.1), peuplés par l'ingestion 28.2. Un label **assignable** = label du contrat actif **dont `mode = ControlHubLabelMode::Free`**.

- [x] **Task 4 — Service de mapping `WorkstationGroupLabelService`** (AC: #2–#7, cœur logique)
  - [x] Créer `app/Services/ControlHub/WorkstationGroupLabelService.php` (`declare(strict_types=1)`). **Patron** : `app/Services/Parc/WorkstationGroupService::updateGroup()` (résolution + validation + `DB::transaction` + `Log::info`).
  - [x] `assignLabel(WorkstationGroup $group, string $labelName): void` :
    - Résoudre le contrat actif (`ControlHubContract::active()`). Si **aucun** contrat actif → exception (rien à assigner).
    - Charger le label par nom dans `contract->labels()->where('name', $labelName)->first()`. **Absent** ⇒ `LabelAssignmentException::unknown($labelName)` (AC #6).
    - `mode === Reserved` ⇒ `LabelAssignmentException::reserved($labelName)` (AC #5).
    - Si `$group->controlhub_label` est **déjà** renseigné **et ≠ `$labelName`** ⇒ `LabelAssignmentException::alreadyLabeled($group, $labelName)` (AC #4, « 1 max »). Si **=== `$labelName`** ⇒ **no-op idempotent** (return sans erreur).
    - Sinon : `DB::transaction` → `$group->controlhub_label = $labelName; $group->save();` + `Log::info`.
  - [x] `detachLabel(WorkstationGroup $group): void` : `controlhub_label = null; save()` dans une transaction (AC #7). (Ne **pas** déléguer à `WorkstationGroupService::updateGroup()` qui **throw sur `isLocked()`** — le mapping de label est un concern distinct du verrou AD ; documenter ce choix.)
  - [x] **NE PAS** réutiliser `WorkstationGroupService::updateGroup()` pour écrire le label (il lève `RuntimeException` si le groupe est `locked`, ce qui n'a pas de sens pour un label libre). Écriture ciblée sur la seule colonne `controlhub_label`.
  - [x] **R3** : aucun « central » dans noms/messages.

- [x] **Task 5 — Exception de domaine `LabelAssignmentException`** (AC: #4, #5, #6)
  - [x] Créer `app/Exceptions/ControlHub/LabelAssignmentException.php` (extends `\RuntimeException`), avec fabriques statiques `reserved(string $name)`, `unknown(string $name)`, `alreadyLabeled(WorkstationGroup $g, string $name)`, `noActiveContract()`. Messages FR explicites et **affichables** (repris en toast). **R3** : aucun « central ». **Patron** : `app/Exceptions/ControlHub/InvalidUpstreamContractException.php` (28.2).

- [x] **Task 6 — UI Livewire : édition du label dans la page de parc** (AC: #9)
  - [x] **Édition** : `resources/views/pages/parc/groups/[id]/edit/index.blade.php` (Livewire SFC) — ajouter une propriété publique `public string $controlhubLabel = '';` (`''` = aucun, miroir exact du pattern `environment` déjà en place : `'' = non déclaré → null`).
    - `loadGroup()` : `$this->controlhubLabel = $this->group->controlhub_label ?? '';`.
    - Exposer à la vue la liste des labels assignables : `$activeContract = ControlHubContract::active(); $freeLabels = $activeContract?->labels()->where('mode', ControlHubLabelMode::Free)->orderBy('name')->get() ?? collect();` (+ le label réservé courant éventuel, en lecture seule).
    - `save()` : après l'`updateGroup` existant, router le label via le **`WorkstationGroupLabelService`** : si `controlhubLabel === ''` → `detachLabel()` ; sinon `assignLabel($group, $controlhubLabel)`. **Capturer** `LabelAssignmentException` → `toastError($e->getMessage())` (cf. trait `WithToasts`, `app/Components/Traits/WithToasts.php`) et **ne pas** rediriger (rester sur le formulaire).
  - [x] **Création** : `resources/views/pages/parc/groups/new/index.blade.php` — même sélecteur ; après création du groupe, `assignLabel()` si un label libre est choisi.
  - [x] **Vue** : ajouter une section « Label de contrat amont » dans `resources/views/pages/parc/groups/[id]/edit/_partials/form.blade.php` (et l'équivalent création) — `<select wire:model="controlhubLabel">` avec option « Aucun » + labels libres. Si `$activeContract === null` → masquer la section (ou note « Aucun contrat amont actif »). Si le groupe porte un label **réservé** (cas 30.3), l'afficher **désactivé** (`disabled`, badge « réservé — imposé par l'amont »), jamais éditable par le refnum.
  - [x] Respecter le routing filesystem (pages/_partials) + Livewire SFC. **Pas** de méthode Livewire nommée `upload` (réservée — mémoire projet).

- [x] **Task 7 — Autorisation (Gate scopé)** (AC: #8)
  - [x] Dans `save()` (et l'action de création), **autoriser** explicitement : `$this->authorize('update-workstationGroup', $this->group);` (Gate de `app/Policies/WorkstationGroupPolicy.php`, mappé `update-workstationGroup ⇒ update`, supporte délégations scopées via `PermissionService`). Le refnum (admin instance) passe ; un délégué hors périmètre est refusé **avant** écriture.
  - [x] Ne **pas** introduire de nouveau Gate : réutiliser `update-workstationGroup` existant (le mapping de label EST une modification du parc).

- [x] **Task 8 — Tests HÔTE (php8.4 + sqlite, `RefreshDatabase`)** (AC: #1–#10)
  - [x] `tests/Feature/ControlHub/WorkstationGroupLabelTest.php` (préparer l'état via factories `ControlHubContractFactory` + `ControlHubContractLabelFactory` de 28.1 — états `forLabel`/`reserved`/`free`, et `WorkstationGroupFactory`). **Seeder les labels directement** (ne pas dépendre du transport/ingestion 28.2).
    - migration jouée → `Schema::hasColumn('workstation_groups','controlhub_label')` (AC #1).
    - `assignLabel` d'un label **free** → colonne renseignée (AC #2).
    - création de groupe + label free (AC #3).
    - **2e label différent refusé** (`LabelAssignmentException`) + groupe inchangé ; ré-assignation **du même** label = no-op sans erreur (AC #4).
    - label **reserved** → exception, base inchangée (AC #5).
    - label **inconnu** (absent du contrat actif) → exception (AC #6).
    - `detachLabel` → `null` (AC #7).
    - **standalone** : sans contrat actif, `ControlHubContract::active()` null, aucun label proposé, comportement parc inchangé (AC #10 / NFR3).
    - **R3** : introspection — aucun identifiant livré (FQCN/colonne/méthode) ne contient `central`.
  - [x] Test Livewire de la page d'édition (`Livewire::test(...)` sur le SFC) : le `<select>` liste les labels free, exclut les reserved ; `save()` avec un label assigné persiste ; un label hors contrat ⇒ toast d'erreur, pas de redirection (AC #9). Patron : tests Livewire existants des pages parc.
  - [x] Test **autorisation** : un user sans droit `update` sur le groupe ⇒ `assignLabel`/`save` refusé (AC #8). Patron : tests de `WorkstationGroupPolicy` / délégations.
  - [x] **Piège SQLite** : ne tester ni longueur `varchar`, ni unicité reposant sur `NULL` (mémoire `sqlite_tests_no_varchar_enforcement`). L'invariant « 1 max » est testé **par comportement** (refus du 2e label), pas par contrainte DB.

- [x] **Task 9 — Doc QA (append-only)** (AC: tous)
  - [x] **Enrichir** `docs/qa/domains/controlhub-contract.md` par une **nouvelle section** « Story 30.2 — Mapping refnum d'un label → WorkstationGroup » (append ; ne jamais créer de fichier QA par story). Décrire : colonne `controlhub_label`, règles d'assignation (free only, 1 max, réservé/inconnu refusés), Gate `update-workstationGroup`, comportement standalone, points de vérification e2e.
  - [x] Mettre à jour l'entrée `docs/qa/README.md` du domaine `controlhub-contract` (append au libellé existant, mentionner Story 30.2).

- [x] **Task 10 — Validation finale**
  - [x] `php artisan test --filter WorkstationGroupLabel` (HÔTE) → vert.
  - [x] Non-régression : `php artisan test --filter ControlHubContract` (48 tests 28.x verts) et la suite des pages parc / `WorkstationGroup`.
  - [x] `grep` : aucun « central » dans les fichiers livrés ; aucun chemin existant modifié au-delà des pages parc édition/création + modèles ciblés (NFR3).


### Périmètre — ce qui EST / N'EST PAS dans 30.2

**DANS** : migration `controlhub_label` sur `workstation_groups` ; exposition modèle (fillable + helpers + scope) ; helper `ControlHubContract::active()` ; service `WorkstationGroupLabelService` (assign/detach) + exception de domaine ; validation (free only, 1 max, réservé/inconnu refusés) ; Gate scopé ; UI refnum (sélecteur de label dans l'édition + la création de parc) ; tests HÔTE + doc QA append.

**HORS** (ne pas déborder — chaque point a sa story) :
- **Garantie d'existence des groupes imposés** (création/verrou des groupes à label **réservé** type `bureau_direction`) → **Story 30.3**. En 30.2, on **affiche** un label réservé porté en lecture seule mais on ne le **crée/supprime/verrouille** pas.
- **Résolution `StateCompiler` d'un item ciblant `label:<nom>`** (propagation à tous les groupes portant le label, règle verrou/permissif) → **Story 30.4**. Ici on prépare juste le scope `carryingControlHubLabel()` ; on **ne touche pas** `app/Services/Agent/StateCompiler.php`.
- **Validation prédictive de collision insoluble** (deux verrous amont contradictoires sur un poste) → **Story 30.5**.
- **Ingestion / transport** des labels → **Epic 28** (déjà livré, cf. Dépendances). 30.2 **consomme** `controlhub_contract_labels` ; ne ré-ingère rien.

### Décision de design — lien WG↔label : **colonne string `controlhub_label`**, PAS de FK dure

Deux options pour matérialiser « le groupe porte ce label, 1 max » :

| Option | « 1 max » | Robustesse | Verdict |
|---|---|---|---|
| (a) FK nullable `controlhub_contract_label_id` → `controlhub_contract_labels.id` | colonne simple ✔ | **Fragile** : les labels sont **réconciliés** (prune + re-create) à chaque ingestion 28.2 ; un label retiré puis re-déclaré change d'`id` → la FK saute (`nullOnDelete`) ou bloque. À la **rupture du lien** (Epic 32) le contrat — donc ses labels — peut disparaître : coupler un parc **durable** à un label **éphémère/singleton** est incorrect. | ✗ |
| (b) **string nullable `controlhub_label` = nom du label** | colonne simple ✔ | **Robuste** : le rattachement par **nom** survit à la réconciliation et à la rupture du lien ; **aligné sur le précédent 28.1** (`ControlHubContractImposedGroup.label_name` = string, **sans FK dure**, choix explicitement documenté) ; **aligné sur la résolution-par-nom de 30.4** (`label:<nom>`). | ✔ **RETENU** |

⇒ **Colonne `controlhub_label` (string, nullable, indexée)**. L'intégrité référentielle est assurée **à l'assignation** (le service vérifie que le nom correspond à un label **free** du contrat **actif**), pas par une contrainte DB — cohérent avec le fait que les labels sont une projection **réception-side** volatile. Conséquence assumée (hors scope, à traiter en 30.4) : un groupe peut conserver un `controlhub_label` « dangling » si le label disparaît du contrat ; sans item amont ciblant ce nom, l'effet est nul.

### Code réel à réutiliser (ancrage exact — ne rien réinventer)

- **Modèle label** : `app/Models/ControlHubContractLabel.php` (28.1, **commité sur `main`**) — `name`, cast `mode → App\Enums\ControlHubLabelMode` (`Free='free'`, `Reserved='reserved'`), `belongsTo contract()`.
- **Contrat racine** : `app/Models/ControlHubContract.php` — relation `labels(): HasMany`. Résolution du singleton actif **identique** à `ControlHubContractIngestionService::resolveActiveContract()` (28.2) : `where('link_state', ControlHubLinkState::Active)->first()`. Factoriser en `ControlHubContract::active()`.
- **Service parc** : `app/Services/Parc/WorkstationGroupService.php` — patron `updateGroup()` (résolution + `DB::transaction` + `Log::info`). ⚠️ il **throw sur `isLocked()`** : ne **pas** y router l'écriture du label.
- **Pages parc** : `resources/views/pages/parc/groups/[id]/edit/index.blade.php` (+ `_partials/form.blade.php`) et `.../groups/new/index.blade.php` — SFC Livewire ; **pattern `environment`** (`public string $environment = ''` ; `'' = non déclaré → null` ; `tryFrom` à la sauvegarde) à **copier** pour `controlhubLabel`.
- **Toasts** : trait `app/Components/Traits/WithToasts.php` (`toastError`, `toastSuccess`) — déjà `use`-é par la page d'édition.
- **Gate** : `app/Policies/WorkstationGroupPolicy.php` — `update-workstationGroup ⇒ update` (délégations scopées via `PermissionService`). `$this->authorize('update-workstationGroup', $group)`.
- **Exception** : patron `app/Exceptions/ControlHub/InvalidUpstreamContractException.php` (28.2).
- **Factories** : `database/factories/ControlHubContractFactory.php` + `ControlHubContractLabelFactory.php` (états `free`/`reserved`/`forLabel`), `database/factories/WorkstationGroupFactory.php`.

### Garde-fous projet CRITIQUES (contraintes de la story)

- **R3 — Vocabulaire (BLOQUANT)** : **INTERDIT** — « central » dans tout nom de colonne, classe, méthode, propriété, exception, message ou commentaire d'identifiant. Vocabulaire : « amont »/`ControlHub*`/`label`. [mémoires `project_contrat_manage_se5_upstream`, `legacy_central_vs_local_split` ; prd#R3]
- **NFR3 — Standalone préservé** : sans contrat amont actif, **aucun** label proposé, **aucune** contrainte ajoutée, comportement parc **strictement inchangé**. La colonne `controlhub_label` est nullable sans défaut métier ; les chemins parc existants ne doivent pas se mettre à dépendre du contrat. [prd#NFR3]
- **Propriété par-parc = page du groupe** : le mapping de label se gère dans le **formulaire d'édition du groupe** (+ création), pas dans un écran séparé. [mémoire `feedback_per_group_property_belongs_on_group_pages`]
- **Modale réutilisable** : si une confirmation est nécessaire (ex. détachement), utiliser la modale réutilisable + son bouton déclencheur (`resources/views/components/molecules/modal`, `components/molecules/confirm-modal.blade.php`). Pour un simple `<select>` dans le form, pas de modale requise.
- **Livewire** : ne jamais nommer une action `upload` (réservée). [mémoire `project_livewire_reserved_upload_method`]

### Project Structure Notes

- **Nouveaux fichiers** : `database/migrations/2026_06_27_100000_add_controlhub_label_to_workstation_groups.php`, `app/Services/ControlHub/WorkstationGroupLabelService.php`, `app/Exceptions/ControlHub/LabelAssignmentException.php`, `tests/Feature/ControlHub/WorkstationGroupLabelTest.php`.
- **Fichiers modifiés** : `app/Models/WorkstationGroup.php` (fillable + helpers + scope), `app/Models/ControlHubContract.php` (méthode `active()`), `resources/views/pages/parc/groups/[id]/edit/index.blade.php` (+ `_partials/form.blade.php`), `resources/views/pages/parc/groups/new/index.blade.php`, `docs/qa/domains/controlhub-contract.md` (append), `docs/qa/README.md` (append).
- **Racine = projet Laravel** (artisan/app à la racine ; pas de préfixe `laravel/`). [mémoire `root_is_laravel`]
- **Tests sur HÔTE** (php8.4 + `pdo_sqlite`), jamais la VM ; ne pas interagir avec la VM depuis ce worktree. [mémoires `phpunit_test_env_host_vs_vm`, `feedback_worktree_no_vm_sync`]

### Pièges identifiés

1. **Ne pas router l'écriture du label via `WorkstationGroupService::updateGroup()`** (il `throw` sur `isLocked()`) — utiliser le service dédié sur la seule colonne `controlhub_label`.
2. **Idempotence d'assignation** : ré-assigner le **même** label ne doit **pas** lever « 1 max » (AC #4) — seul un label **différent** est refusé.
3. **Validation `reserved` vs `unknown`** : deux refus distincts (messages différents) ; ordre conseillé = inconnu (absent) avant réservé (présent mais non attribuable).
4. **Cohérence cible de la résolution du contrat actif** : utiliser **exactement** `link_state = Active` (singleton 28.2), pas une heuristique « dernier reçu ».
5. **SQLite** : `varchar(n)` non appliqué et `NULL ≠ NULL` dans les index — l'invariant « 1 max » est **applicatif**, testé par comportement, pas par contrainte DB.
6. **Label réservé déjà porté** (préfigure 30.3) : l'UI doit l'afficher **disabled** ; le service doit refuser que le refnum l'écrase via un label libre (la branche « 1 max » couvre déjà ce cas : un groupe avec label réservé a `controlhub_label` non vide).
7. **R3 / standalone** : pas de lecture du contrat injectée dans un chemin parc existant **autre** que l'UI de mapping ; sans contrat actif, la section UI disparaît.

### References

- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 30.2] — AC d'origine (label libre → groupe/création, 1 max ; réservé non attribuable, FR10).
- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Epic 30] — labels & ciblage sous-instance (FR9–FR13) ; matrice FR (lignes 61–91).
- [Source: _bmad-output/implementation-artifacts/28-1-modele-et-persistance-du-contrat-amont.md] — `controlhub_contract_labels`, modèle `ControlHubContractLabel`, enum `ControlHubLabelMode`, précédent `imposed_groups.label_name` (rattachement par nom, sans FK dure).
- [Source: _bmad-output/implementation-artifacts/28-2-reception-idempotente-contrat-amont.md] — `resolveActiveContract()` (singleton `link_state=active`), réconciliation prune/recreate (raison du choix « par nom »).
- [Source: app/Models/WorkstationGroup.php] — fillable, helpers, scopes (patron d'ajout).
- [Source: app/Models/ControlHubContractLabel.php ; app/Models/ControlHubContract.php] — labels + relation `labels()`.
- [Source: app/Services/Parc/WorkstationGroupService.php#updateGroup] — patron service (transaction/log) + piège `isLocked()`.
- [Source: app/Policies/WorkstationGroupPolicy.php] — Gate `update-workstationGroup` scopé.
- [Source: resources/views/pages/parc/groups/[id]/edit/index.blade.php] — SFC Livewire + pattern `environment` (`''→null`, `tryFrom`).
- [Source: app/Components/Traits/WithToasts.php] — toasts.
- [Source: app/Exceptions/ControlHub/InvalidUpstreamContractException.php] — patron exception de domaine ControlHub.

## Dépendances

- **Amont (bloquantes) — toutes satisfaites** :
  - **Modèle des labels (Story 28.1)** — **LIVRÉ et commité sur `main`** : `controlhub_contract_labels` (migration `2026_06_26_100000_create_controlhub_contract_tables.php`), modèle `ControlHubContractLabel`, enum `ControlHubLabelMode` (`free`/`reserved`). **C'est la fondation directe de 30.2.**
  - **Story 30.1 (« Réception des labels et groupes imposés ») — ABSORBÉE par les fondations de l'Epic 28** : la réception/persistance des labels (nom + mode) et groupes imposés est **déjà** couverte par 28.1 (schéma) + 28.2 (ingestion). **30.1 n'est donc pas une dépendance bloquante à créer** ; elle n'existe pas comme story distincte à développer. (Si une entrée `30-1-*` figure au backlog, elle est de facto sans objet — le périmètre est couvert par Epic 28.)
- **Amont (non bloquantes)** :
  - **Story 28.2 (ingestion idempotente)** — en **`review`** (committée/stagée). **Non bloquante pour le dev de 30.2** : les tests **seedent les labels directement** via les factories 28.1 ; le service de mapping lit `controlhub_contract_labels` quelle que soit la source de peuplement. En production, c'est l'ingestion 28.2 qui peuple ces labels.
- **Aval (dépendent de 30.2)** :
  - **30.3** — garantie d'existence des groupes imposés (consomme `controlhub_label` + l'UI disabled du label réservé).
  - **30.4** — résolution `StateCompiler` par label (réutilise le scope `carryingControlHubLabel()`).
  - **30.5** — validation prédictive de collision (au moment de l'assignation introduite ici).

## Testing

- **Cible d'exécution : HÔTE** (php8.4 + `pdo_sqlite`), **jamais la VM**. [mémoire `phpunit_test_env_host_vs_vm`]
- `DB_CONNECTION=sqlite` (cf. `phpunit.xml`), trait `RefreshDatabase`.
- Filtre ciblé : `php artisan test --filter WorkstationGroupLabel` ; non-régression : `--filter ControlHubContract` (48 tests 28.x) + suite pages parc / `WorkstationGroup`.
- Couverture : migration (colonne présente), assign free, création + label, **1 max** (2e label refusé / même label idempotent), **reserved refusé**, **inconnu refusé**, detach, **Gate** (refus hors périmètre), **UI Livewire** (select free-only, save, toast d'erreur), **standalone** (sans contrat actif), **R3** (introspection).
- **Pièges SQLite** : invariant « 1 max » testé par **comportement** (refus), pas par contrainte DB ; ne pas tester de longueur `varchar` ni d'unicité sur `NULL`.
- ⚠️ **VM** : migrations **pas auto-jouées** par le dev-cycle (SQLite uniquement) ; ne pas présumer la colonne présente côté VM. [mémoire `vm_migrations_not_auto_applied`]

## Recommandation Modèle Dev

**`opus`.**

Justification : story à **logique métier et invariants subtils**, malgré une UI légère.
1. **Décision d'architecture engageante** (colonne string « par nom » vs FK dure) déjà tranchée ici mais à implémenter avec ses conséquences (robustesse réconciliation/rupture du lien) — un dev pressé recréerait une Fks fragile.
2. **Matrice de refus** (free/reserved/unknown/1-max/idempotent) avec des cas limites faciles à confondre (réserved vs inconnu, ré-assignation du même label).
3. **Garde-fous transverses simultanés** : R3 (vocabulaire), NFR3 (standalone — ne pas injecter de lecture du contrat dans un chemin parc existant), Gate scopé, piège `isLocked()` de `updateGroup()`.
4. **Migration** + cohérence avec le singleton 28.2 (`link_state=active`).
Le dev-cycle routera la **review vers le modèle opposé** (sonnet/fable) ; placer **opus** sur l'implémentation met le raisonnement là où le risque d'erreur d'invariant et d'effet de bord standalone est le plus élevé.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m]

### Debug Log References

- `vendor/phpunit/phpunit/phpunit --filter WorkstationGroupLabel` → **15/15 verts** (51 assertions).
- `vendor/phpunit/phpunit/phpunit --filter ControlHubContract` → **48/48 verts** (246 assertions, non-régression 28.x).
- `vendor/phpunit/phpunit/phpunit tests/Feature/ControlHub` → **54/54 verts** (240 assertions).
- `vendor/phpunit/phpunit/phpunit --filter 'GroupEditEnvironmentTest|ParcBulkEnvironmentTest'` → **8/8 verts** (après ajout du Gate `update-workstationGroup` à la page d'édition, `GroupEditEnvironmentTest::setUp` accorde désormais la gate — pages parc édition modifiées).
- `MachineShowPageTest` → 19/19 verts.
- Worktree sans `vendor/` : dépendances provisionnées en local (copie du `vendor/` du repo principal + `composer dump-autoload` pour réaligner le baseDir sur le worktree) ; tests HÔTE php8.4 + sqlite :memory: (phpunit.xml), jamais la VM.

### Completion Notes List

- **Task 1** — Migration `2026_06_27_100000_add_controlhub_label_to_workstation_groups.php` : colonne `controlhub_label` string nullable indexée (`wg_controlhub_label_idx`) `after('controlhub_version')`, garde `hasColumn`, `down()` dropIndex+dropColumn. Aucun « central », nullable sans défaut métier (NFR3).
- **Task 2** — `WorkstationGroup` : `controlhub_label` ajouté au `$fillable` + PHPDoc `@property`, helpers `hasControlHubLabel()`/`controlHubLabel()`, scope `scopeCarryingControlHubLabel()` (réutilisé 30.4). Aucune relation Eloquent dure (rattachement par nom).
- **Task 3** — `ControlHubContract::active(): ?self` : résolution `link_state = active` **identique** à `resolveActiveContractIngestionService::resolveActiveContract()` (28.2).
- **Task 4** — `WorkstationGroupLabelService::assignLabel()/detachLabel()` : matrice de refus (noActiveContract / unknown → reserved → alreadyLabeled), idempotence du même label (no-op return), écriture ciblée sur `controlhub_label` dans `DB::transaction` + `Log::info`. **N'utilise PAS** `WorkstationGroupService::updateGroup()` (throw sur `isLocked()` — concern distinct documenté).
- **Task 5** — `LabelAssignmentException` (extends `RuntimeException`) : fabriques `noActiveContract()/unknown()/reserved()/alreadyLabeled()`, messages FR affichables (toast). Patron `InvalidUpstreamContractException`.
- **Task 6** — UI Livewire SFC : pages d'édition (`[id]/edit/index.blade.php` + `_partials/form.blade.php`) et de création (`new/index.blade.php`). Propriété `controlhubLabel` (miroir du pattern `environment` `''→null`), `loadControlHubLabels()` (contrat actif + labels free triés ; label réservé/hors-liste porté exposé en lecture seule via `reservedLabelHeld`). Section masquée si pas de contrat actif (NFR3). Refus métier → `toastError($e->getMessage())` sans redirection. Aucune action Livewire nommée `upload`.
- **Task 7** — Gate scopé : `authorize('update-workstationGroup', $group)` en tête de `save()` (édition) ; `authorize('create-workstationGroup')` en tête de `save()` (création — même socle `canAdminComputers`, group inexistant au moment de la création) AVANT toute écriture.
- **Task 8** — `tests/Feature/ControlHub/WorkstationGroupLabelTest.php` (15 tests) couvrant les 10 AC : migration, scope, assign free, création+label (Livewire), 1-max + idempotence, reserved, unknown, detach, Gate (refus `assertForbidden` + group inchangé), UI (select free-only, save, toast sans redirect), standalone, R3 (introspection). `Queue::fake()` neutralise le job AD-sync de l'observer.
- **Task 9** — Doc QA : `docs/qa/domains/controlhub-contract.md` Section 6 (scénarios 6.1–6.9 + checklist) ; `docs/qa/README.md` ligne `controlhub-contract` enrichie (Story 30.2).
- **Déviations** : (1) page de **création** → gate `create-workstationGroup` (sémantiquement correct ; la story citait `update-workstationGroup` mais le groupe n'existe pas encore à la création ; les deux gates résolvent à `canAdminComputers` → protection équivalente). (2) `tests/Feature/Livewire/Parc/GroupEditEnvironmentTest.php` mis à jour (setUp accorde la gate) : régression de test **attendue** car la page d'édition enforce désormais l'autorisation en composant (défense en profondeur requise par AC8/Task 7) — le test était sous-provisionné.
- **Garde-fous vérifiés** : R3 — `grep -riE central` sur les fichiers livrés = vide + test d'introspection. NFR3 — sans contrat actif, `active()=null`, section UI masquée, aucun label proposé, comportement parc inchangé (test `standalone_*`). Pré-existants NON imputables (prouvés via `git stash`) : `WorkstationGroupEnvironmentCastTest` (3 err, LDAP injoignable) et `PrintersTabTest` (col `printer_workstation_group.is_default` absente du schéma test) — aucun fichier de ces chemins touché.

### File List

**Créés :**
- `database/migrations/2026_06_27_100000_add_controlhub_label_to_workstation_groups.php`
- `app/Services/ControlHub/WorkstationGroupLabelService.php`
- `app/Exceptions/ControlHub/LabelAssignmentException.php`
- `tests/Feature/ControlHub/WorkstationGroupLabelTest.php`

**Modifiés :**
- `app/Models/WorkstationGroup.php` (fillable + PHPDoc + helpers + scope)
- `app/Models/ControlHubContract.php` (méthode statique `active()`)
- `resources/views/pages/parc/groups/[id]/edit/index.blade.php` (propriété `controlhubLabel` + `loadControlHubLabels()` + Gate + routage label dans `save()`)
- `resources/views/pages/parc/groups/[id]/edit/_partials/form.blade.php` (section « Label de contrat amont »)
- `resources/views/pages/parc/groups/new/index.blade.php` (propriété + load + Gate + assignation à la création + sélecteur)
- `tests/Feature/Livewire/Parc/GroupEditEnvironmentTest.php` (setUp : accorde la gate `update-workstationGroup` désormais enforced en composant)
- `docs/qa/domains/controlhub-contract.md` (Section 6 — Story 30.2, append)
- `docs/qa/README.md` (ligne domaine `controlhub-contract` enrichie 30.2)
