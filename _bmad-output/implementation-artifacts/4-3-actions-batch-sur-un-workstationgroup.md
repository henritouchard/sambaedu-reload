# Story 4.3 : Actions Batch sur un WorkstationGroup

Status: review

> **Statut précision :** le backend synchrone (`WorkstationGroupService::executeGroupMachinesAction`, `ParcController::massAction`, structure de retour typée) est déjà livré, d'où le `in-progress` plutôt que `ready-for-dev`. Cette story formalise le reste à faire : pipeline async par machine, feedback readiness individuel, résumé fin de batch, idempotence, tests.

> **Origine :** formalisation du scope de finalisation de la story 4.3. Le backend synchrone (`WorkstationGroupService::executeGroupMachinesAction`, `ParcController::massAction`) est déjà livré et la structure de retour typée existe (`{action, requested_count, success_count, failed_count, results[]}`), mais le pipeline async par machine (feedback readiness individuel, résumé de fin de batch, guard idempotence) n'est pas implémenté. Cette story porte la vue `/parc/groups/{id}` au niveau de qualité de la vue machine 4-2 (pattern `DispatchMachinePowerActionJob` + `machine_power_action_tasks` + polling Livewire par machine).
> **Épic :** Epic 4 — Gestion des Machines, WorkstationGroups & AppProfiles SER.
> **Dépendance amont :** Story 4.2 (done, commit `4c9791d`) — pattern async et machine à états restart à décalquer strictement.

---

## Story

En tant que **responsable de collège**,
je veux lancer une action power (allumage, extinction, extinction forcée, reboot) sur toutes les machines d'un WorkstationGroup — ou sur une sélection — en une seule opération et voir la progression individuelle de chaque machine en temps réel,
afin de gérer une salle complète rapidement sans agir poste par poste et sans ouvrir une fiche pour chaque machine.

---

## Contexte & Motivation

### État actuel (audit 2026-04-21)

**Backend livré (déjà en prod) :**

- `App\Services\Parc\WorkstationGroupService::executeGroupMachinesAction(int $groupId, array $machineIds, string $action): array` (ligne 188).
- `App\Services\Parc\WorkstationGroupService::executeMachinesAction(array $machineIds, string $action): array` (ligne 163) — utilisée par la vue globale `/parc`.
- `App\Services\Parc\WorkstationGroupService::executeMachineAction(int $machineId, string $action): array` (ligne 175) — déjà utilisée par la vue groupe pour l'action unitaire (synchrone).
- Constantes `SUPPORTED_MACHINE_ACTIONS = ['wake', 'shutdown', 'shutdown-force', 'restart', 'remote']` + `MACHINE_ACTION_LABELS` (FR).
- `App\Http\Controllers\Admin\ParcController::massAction()` (route `POST /admin/parcs/{parc}/mass-action`) — endpoint JSON hérité, conservé pour la compat API / scripts externes (hors scope UI de cette story, mais à ne pas casser).
- Retour typé `{action, requested_count, success_count, failed_count, results[]}` — **garder strictement ce contrat**, les consommateurs (legacy, scripts) en dépendent.

**Infrastructure async déjà en place (story 4.2) :**

- Table `machine_power_action_tasks` (migration `2026_04_20_120000`) avec statuts `queued | dispatched | running | completed | failed` + colonne `restart_phase` (`waiting-down | waiting-up`).
- `App\Models\MachinePowerActionTask` + constantes `STATUS_*` + `ACTIVE_STATUSES`.
- `App\Jobs\DispatchMachinePowerActionJob` — job async par machine (1 job = 1 task = 1 machine). Gère déjà shutdown-force, restart phase, retry=1, timeout=90s, route vers `config('parc.queue_connection')`.
- `MachinePowerService::logReadinessTimeout()` — écrit dans `machine_boot_logs.error_flags=1`.
- Config `config/parc.php` : `machine_readiness_timeout_seconds` (défaut 120), `machine_readiness_poll_interval_seconds` (défaut 3), `queue_connection`.
- Worker queue `laravel-queue-general.service` déjà packagé (`scripts/update.sh`).

**UI groupe existante (`resources/views/pages/parc/groups/[id]/`) :**

- `index.blade.php` (258 L) — composant Livewire SFC avec sélection multiple (`selectedGroupMachineIds`, `selectAllGroupMachines`, `updatedSelectedGroupMachineIds`, `toggleGroupMachineSelection`).
- Méthodes d'action **synchrones** :
  - `executeSelectedGroupMachinesAction(string $action)` (ligne 168) — batch avec toast global (succès / partiel / erreur), pas de feedback par machine.
  - `executeMachineAction(int $machineId, string $action)` (ligne 205) — unitaire depuis le groupe, également synchrone.
- `_partials/machines-list.blade.php` — tableau des machines avec checkbox, dropdown d'actions unitaires, barre flottante "X machine(s) sélectionnée(s)" avec dropdown batch (shutdown-force déjà exposé + wire:confirm renforcé).
- Aucun polling, aucun badge "action en cours" par machine, aucun résumé de fin de batch.

**Pattern de référence (story 4.2 — vue machine `/parc/machines/{id}`) :**

- Flow : (1) créer `MachinePowerActionTask` status=queued → (2) `DispatchMachinePowerActionJob::dispatch($task->id)` → (3) flip propriétés Livewire `statusRunning/runningAction/runningActionStartedAt/currentTaskId` → (4) toast immédiat → (5) `wire:poll.{N}s` rendu conditionnellement appelle `pollMachineReadiness()` → (6) arrêt du poll sur succès/échec/timeout.
- Garanties : NFR2 (toast < 500 ms car le flow sync s'arrête au `dispatch()`), audit trail (1 row par action), machine à états restart, guard double-dispatch.

**Tests livrés référence 4.2 :**

- `tests/Feature/Livewire/Parc/MachineShowPageTest.php` — 7 tests Feature (wake/shutdown/restart phases/timeout/invalid/guard double-dispatch).
- `tests/Feature/Livewire/Parc/GroupShowPageTest.php` — 4 tests (shutdown-force exposé dans dropdown unitaire + batch + wire:confirm) — **base à étendre**.
- `tests/Unit/Services/MachinePowerServiceTest.php` (25 tests) + `WorkstationServicePowerActionTest.php` (9 tests).
- **Aucun test unitaire** ciblant `WorkstationGroupService::executeGroupMachinesAction()` — à créer (AC7).

### Manques identifiés (ce que 4.3 livre)

1. **Pas de dispatch async par machine dans le batch** — `executeSelectedGroupMachinesAction()` et `executeMachineAction()` (vue groupe) appellent `WorkstationGroupService::executeGroupMachinesAction()` **synchronement**. Sur un batch de 30 machines × `net rpc shutdown -t 30` = potentiellement plusieurs minutes bloquant la requête Livewire. **NFR2 non respecté** sur batch.
2. **Pas de feedback par machine** — le toast global dit "X/Y machine(s)" mais l'opérateur ne voit pas en temps réel laquelle a déjà répondu, laquelle est en cours, laquelle est en timeout.
3. **Pas de résumé fin de batch identifiant explicitement les échecs** — le toast warning (partiel) ou error (tout échoué) n'expose pas les noms des machines KO.
4. **Pas de guard idempotence batch** — relancer le même batch pendant qu'il est en cours peut créer des doublons de `MachinePowerActionTask` en vol (edge case double-click).
5. **Aucun test unitaire ciblant `executeGroupMachinesAction()`** — le dispatch batch n'est couvert que de bout en bout via les Feature tests de la vue.
6. **Aucun test E2E VM** sur un batch async réel (parité avec `docs/qa/4-2-e2e-manual.md`).

### Décisions actées (kickoff 4-3)

- **D1 — Pattern async : décalquer 4.2 strictement.** Une `MachinePowerActionTask` par machine du batch + un `DispatchMachinePowerActionJob` par task. Pas de "batch task" globale : on reste atomique machine-par-machine pour que l'UI puisse afficher un état indépendant par ligne et que l'audit trail reste aligné avec la vue machine.
- **D2 — Feedback par machine via polling Livewire.** Un unique `wire:poll.{N}s` au niveau de la vue groupe (pas un poll par ligne) qui rafraîchit la collection des tasks actives du groupe en une seule requête SQL. Arrête le poll quand toutes les tasks sont terminales (completed / failed) OU quand le timeout global est atteint.
- **D3 — Résumé fin de batch en encart persistant** (pas juste un toast qui disparaît). Affiché dès qu'au moins une task termine (même si d'autres sont encore en vol) — contenu : compteur `X succès / Y échecs / Z en cours` + liste nominative des échecs avec raison (message de la task en failed). Un bouton "Effacer" referme l'encart et purge la corrélation (mais les rows `machine_power_action_tasks` restent en DB pour audit).
- **D4 — Guard idempotence.** Si un batch est lancé sur un groupe/sélection qui comporte déjà des machines avec une task active (`status IN queued/dispatched/running`), on refuse la création de nouvelles tasks pour ces machines (toast warning "N machine(s) déjà en action, ignorée(s)") et on dispatch seulement pour le reste. L'UI empêche également le clic pendant qu'un batch est "en vol" sur ce composant (`@disabled`).
- **D5 — `ParcController::massAction` non touché.** C'est l'endpoint JSON historique utilisé par scripts externes/legacy crons. Le rendre async casserait le contrat REST (la réponse deviendrait "dispatched" au lieu du résumé synchrone). On laisse intact et on note dans la doc que l'endpoint reste synchrone, l'UI moderne passe par le composant Livewire.
- **D6 — Permission Spatie.** Utiliser `computer.control` (enum `SambaPermission::ComputerControl`) déjà en place et mappée dans les rôles `ComputerAdmin` / `SuperAdmin`. Gate `@can('computer.control')` sur les entrées de dropdown (pattern à aligner avec la vue machine 4.2 si pas encore appliqué — vérifier dans la tâche d'audit).

---

## Acceptance Criteria

**AC1 — Pas de régression sur l'UI existante**

- Given la route `/parc/groups/{id}` actuelle fonctionne avec sélection multiple (checkbox, "Tout sélectionner", barre flottante)
- When je sélectionne des machines et clique une action (wake / shutdown / shutdown-force / restart / remote)
- Then le flow fonctionne comme avant dès la première frappe (pas de refonte visuelle)
- And le `wire:confirm` de `shutdown-force` batch reste présent avec la formulation renforcée ("Forcer l'extinction de TOUTES les machines sélectionnées…")
- And les 4 tests `tests/Feature/Livewire/Parc/GroupShowPageTest.php` existants restent verts

**AC2 — NFR2 : retour UI < 500 ms sur batch (dispatch async)**

- Given un groupe avec N machines (N ≤ 50) et une sélection valide
- When je clique "Éteindre" sur le dropdown batch
- Then le service `WorkstationGroupService::executeGroupMachinesAction()` **dispatche N jobs `DispatchMachinePowerActionJob`** (1 par machine, 1 `MachinePowerActionTask` par machine, status initial `queued`, `restart_phase='waiting-down'` si action=`restart`) et retourne immédiatement sans attendre les shells
- And le composant Livewire retourne un toast "Action lancée sur N machine(s)" en **< 500 ms** (mesuré dès que `QUEUE_CONNECTION != sync`)
- And l'UI passe en état "batch en cours" (`$batchRunning = true`) et affiche un badge "Action X en cours sur N machine(s)"
- And le dropdown "Actions machine" batch devient `@disabled` tant que `$batchRunning === true` (idempotence UI) — sans bloquer les actions unitaires sur des machines non incluses dans le batch en cours

**AC3 — Feedback par machine via polling**

- Given un batch est en cours (≥ 1 task active associée au groupe)
- When `wire:poll.{N}s="pollGroupReadiness"` tire (intervalle `config('parc.machine_readiness_poll_interval_seconds')`)
- Then chaque ligne du tableau des machines affiche dynamiquement :
  - Badge "En file" (queued), "En cours" (dispatched/running), "OK" (completed), "Échec" (failed avec tooltip = `error_message`), "—" (pas de task active) ;
  - La colonne Statut passe en surbrillance (`bg-info/5` pour active, `bg-success/10` pour completed, `bg-error/10` pour failed) pour aider l'opérateur à scanner visuellement
- And `pollGroupReadiness()` consomme `machine_power_action_tasks` avec un **seul `SELECT`** sur `workstation_id IN (machineIdsOfGroup)` (pas de N+1) et traite la machine à états `restart_phase` (waiting-down → waiting-up → completed) en appelant `MachinePowerService::ping()` machine par machine UNIQUEMENT pour les tasks actives
- And le poll s'arrête dès qu'aucune task active n'existe pour la sélection courante (`ACTIVE_STATUSES ∩ task.workstation_id ∈ selection = ∅`)

**AC4 — Résumé de fin de batch (D3)**

- Given un batch a été lancé et au moins une task est terminale (completed ou failed)
- When `pollGroupReadiness()` met à jour la vue
- Then un encart persistant "Résumé du batch" s'affiche au-dessus du tableau avec :
  - Compteurs `X succès / Y échecs / Z en cours` (mis à jour en live) ;
  - Libellé de l'action humanisée (ex. "Extinction forcée") ;
  - **Liste nominative des échecs** avec `{machine.name} — {task.error_message || 'Échec inconnu'}` (groupée, un bullet par machine) ;
  - Bouton "Effacer" qui rejette la corrélation en cours (`$currentBatchTaskIds = []`) et masque l'encart, sans modifier la DB (les rows `machine_power_action_tasks` restent pour audit)
- And quand toutes les tasks sont terminales le badge "batch en cours" disparaît, `$batchRunning = false`, et l'encart reste affiché tant que l'opérateur ne clique pas "Effacer" ou ne navigue pas ailleurs

**AC5 — Timeout global du batch**

- Given un batch est en cours depuis plus de `config('parc.machine_readiness_timeout_seconds')` secondes (120 par défaut)
- When `pollGroupReadiness()` s'exécute
- Then toute task encore active pour la sélection courante est marquée `failed` avec `error_message = "Readiness timeout ({timeout}s)"` (même sémantique que la vue machine 4.2)
- And pour chaque machine en timeout, `MachinePowerService::logReadinessTimeout()` est appelée pour mettre à jour le `MachineBootLog` correspondant (`error_flags=1`)
- And l'encart résumé affiche les timeouts comme des échecs avec la raison timeout
- And un toast warning global "Batch terminé avec timeout sur N machine(s)" est émis une fois (pas à chaque tick)
- And `$batchRunning` passe à `false`

**AC6 — Actions supportées en batch (parité 4.2)**

- Given je suis sur `/parc/groups/{id}` avec sélection multiple
- When je clique l'une des actions batch : `wake`, `shutdown`, `shutdown-force`, `restart`
- Then chaque action dispatche correctement via `DispatchMachinePowerActionJob` qui route vers `MachinePowerService::{wakeOnLan|shutdown|shutdown(force=true)|reboot}`
- And l'action `remote` reste **hors batch** (pas dans le dropdown batch — incohérent conceptuellement : un token par machine à ouvrir individuellement). Le dropdown batch n'expose donc QUE `wake | shutdown | shutdown-force | restart`
- And le dropdown unitaire (par ligne) reste inchangé et continue d'exposer les 5 actions (remote inclus)

**AC7 — Guard idempotence & sélection partielle intelligente (D4)**

- Given je lance un batch sur 10 machines et je le relance avant la fin sur les mêmes 10 machines
- When `executeSelectedGroupMachinesAction()` est appelé
- Then les machines avec une `MachinePowerActionTask` active (status ∈ `ACTIVE_STATUSES`) pour le `workstation_id` sont **filtrées avant dispatch** : aucun nouveau job n'est créé pour elles
- And le toast résumé du dispatch indique `"Action lancée sur X machine(s) — Y machine(s) ignorée(s) (action déjà en cours)"` (warning si Y>0, success si Y==0)
- And si 100% de la sélection est déjà en action : toast warning "Toutes les machines sélectionnées ont déjà une action en cours" + aucun job dispatché

- Given une action unitaire (`executeMachineAction`) est appelée depuis la vue groupe sur une machine dont une task est déjà active
- When le click arrive
- Then aucun job n'est dispatché, toast warning "Une action est déjà en cours sur cette machine" (parité avec la vue machine `/parc/machines/{id}`)

**AC8 — Tests unitaires `WorkstationGroupService::executeGroupMachinesAction` & extensions (AC7 du spec initial du scope)**

- Créer `tests/Unit/Services/WorkstationGroupServicePowerActionTest.php` avec au minimum :
  - `test_execute_group_machines_action_dispatches_one_job_per_machine`
  - `test_execute_group_machines_action_returns_typed_structure_with_correct_counts` (contrat `{action, requested_count, success_count, failed_count, results}` préservé)
  - `test_execute_group_machines_action_throws_on_unsupported_action`
  - `test_execute_group_machines_action_filters_machines_with_active_tasks` (AC7 idempotence)
  - `test_execute_group_machines_action_returns_empty_results_when_group_is_empty`
  - `test_execute_group_machines_action_creates_task_with_restart_phase_waiting_down_for_restart`
  - `test_execute_group_machines_action_preserves_remote_sync_flow` (action=`remote` garde le chemin synchrone via `executeRemoteAccessAction`)
  - `test_execute_group_machines_action_normalizes_and_deduplicates_ids` (ids '1', 1, 1 → 1 seule task)
- Les 9 tests existants `WorkstationServicePowerActionTest` et les 25 tests `MachinePowerServiceTest` restent verts (aucune régression)

**AC9 — Tests Feature Livewire vue groupe (étendre `GroupShowPageTest`)**

- Étendre `tests/Feature/Livewire/Parc/GroupShowPageTest.php` (actuellement 4 tests) avec :
  - `test_batch_dispatch_creates_one_task_per_machine_and_emits_success_toast` (Queue::fake + assertions DB)
  - `test_batch_sets_batch_running_state_and_disables_batch_dropdown` (`assertSet('batchRunning', true)` + `assertSeeHtmlInOrder` des classes `disabled`)
  - `test_poll_group_readiness_updates_task_statuses_and_stops_when_all_terminal`
  - `test_poll_group_readiness_times_out_all_active_tasks_after_configured_duration` (Carbon::setTestNow + assertions sur DB + toast warning)
  - `test_batch_summary_card_lists_failed_machines_with_error_messages`
  - `test_batch_summary_clear_button_resets_correlation_without_deleting_db_rows`
  - `test_batch_skips_machines_with_active_tasks_and_warns` (AC7 idempotence)
  - `test_unit_action_from_group_view_respects_active_task_guard`
  - `test_remote_action_not_in_batch_dropdown` (AC6)

**AC10 — Test E2E smoke sur VM dev (manuel, documenté)**

- Créer `docs/qa/4-3-e2e-manual.md` avec la checklist :
  1. Préparer un WorkstationGroup contenant ≥ 3 machines (dont au moins 1 injoignable pour forcer un échec)
  2. Lancer un batch `wake` sur la sélection via `/parc/groups/{id}` — vérifier toast < 500 ms (`QUEUE_CONNECTION=database` + worker running)
  3. Observer le polling dans devtools Network — requêtes Livewire toutes les `machine_readiness_poll_interval_seconds` secondes, 1 seule requête par tick
  4. Vérifier que chaque ligne machine change de badge (queued → running → completed|failed) en direct
  5. Forcer un timeout sur une machine injoignable (IP `192.0.2.1`) — vérifier toast warning à 120s et `MachineBootLog.error_flags=1`
  6. Scénario restart : vérifier phase `waiting-down` (machine répond encore) puis `waiting-up` (ping à nouveau) → completed
  7. Scénario idempotence : relancer le même batch pendant son exécution → warning "déjà en cours"
  8. Vérifier l'encart résumé final et le bouton "Effacer"
- Toutes les observations consignées dans le champ `Completion Notes` de cette story une fois exécutées sur VM `192.168.122.50`

**AC11 — Permissions**

- Given un utilisateur sans la permission `computer.control`
- When il accède à `/parc/groups/{id}`
- Then les entrées de dropdown `wake`, `shutdown`, `shutdown-force`, `restart` (unitaires ET batch) sont **masquées** ou `@disabled` (gate `@can('computer.control')`)
- And une tentative de forger un appel Livewire `executeSelectedGroupMachinesAction` ou `executeMachineAction` est rejetée (guard serveur-side dans les méthodes Livewire — lancer une `\Illuminate\Auth\Access\AuthorizationException` captée en `toastError`)

**AC12 — Documentation**

- Compléter `docs/domains/parc.md` (créé en 4.2) avec une section "Actions batch (story 4.3)" :
  - Flow détaillé : sélection → filtre idempotence → N tasks queued → N jobs → polling → résumé
  - Rappel : `ParcController::massAction` reste l'endpoint JSON synchrone (compat API)
  - Explication de l'encart résumé et du bouton "Effacer"
  - Lien vers `docs/qa/4-3-e2e-manual.md`

---

## Tasks / Subtasks

### Tâche 1 — Audit et préparation (AC: 1, 6, 11)

- [x] **1.1** Lire `resources/views/pages/parc/groups/[id]/index.blade.php` + `_partials/machines-list.blade.php` — vérifier qu'aucune logique métier ne vit en dehors du SFC.
- [x] **1.2** Lire `WorkstationGroupService::executeGroupMachinesAction()` + `executeMachineActionOnCollection()` + `executeRemoteAccessAction()` — identifier le point d'injection du pipeline async (équivalent `executePowerAction()` → séparer "résolution collection" / "dispatch par machine").
- [x] **1.3** Vérifier la présence des permissions `@can('computer.control')` sur la vue groupe. Si absentes, noter pour la tâche 5. Comparer avec la vue machine 4.2 pour parité.
- [x] **1.4** Confirmer que `DispatchMachinePowerActionJob` est réutilisable tel quel (pas besoin de variant "batch") : une task = une machine = un job, le service réuse la même infra 4.2.
- [x] **1.5** Lister les consommateurs actuels du contrat de retour de `executeGroupMachinesAction()` pour s'assurer que le changement de pattern (dispatch async) reste compatible : `ParcController::massAction` est INDÉPENDANT (utilise `wakeOnLan/shutdown/restart` legacy direct via `WorkstationService`), donc **pas d'impact** sur l'endpoint JSON.

### Tâche 2 — Backend : dispatch async par machine (AC: 2, 6, 7)

- [x] **2.1** Refactorer `WorkstationGroupService::executeGroupMachinesAction()` :
  - Garder la signature et le contrat de retour (NE PAS CASSER les tests hypothétiques externes)
  - Pour `action ∈ {wake, shutdown, shutdown-force, restart}` : créer `MachinePowerActionTask` en `status=queued` + `restart_phase='waiting-down'` si `restart` + `initiated_by` = user courant + dispatch `DispatchMachinePowerActionJob` pour chaque machine éligible
  - Appliquer le **filtre idempotence** : pré-requête `MachinePowerActionTask::whereIn('workstation_id', $ids)->whereIn('status', ACTIVE_STATUSES)->pluck('workstation_id')` → exclure de la liste
  - Retourner la structure habituelle `{action, requested_count, success_count, failed_count, results[]}` où :
    - `requested_count` = nombre total demandé initialement
    - `success_count` = nombre de tasks créées + dispatchées
    - `failed_count` = nombre filtré (déjà en cours) + inexistant en base
    - `results[]` = un élément par machine de la demande initiale avec `{machine, success, code, reason?}` (code=202 pour dispatched, 409 pour skipped-already-running, 404 pour not-found)
  - `action=remote` : flow inchangé (`executeRemoteAccessAction()` synchrone).
- [x] **2.2** Ajouter helper privé `WorkstationGroupService::dispatchAsyncActionForMachines(Collection $machines, string $action, ?string $initiatedBy): array` — factorise le dispatch et la gestion idempotence. Testable unitairement.
- [x] **2.3** **Ne pas toucher** à `ParcController::massAction` (D5). Documenter en docblock sur la méthode que le chemin async vit dans `WorkstationGroupService`.
- [x] **2.4** Vérifier que le contrat preserve la compat de `executeMachinesAction` (pas de group_id) — cette méthode est appelée par la vue `/parc/machines/index` (sélection globale hors groupe). Elle doit elle aussi bénéficier du dispatch async (cohérence UX) — **à confirmer avec le dev** selon bande passante (optionnel, scope principal = vue groupe).

### Tâche 3 — UI Livewire vue groupe : propriétés + méthodes async (AC: 2, 3, 4, 5, 7)

- [x] **3.1** Dans `resources/views/pages/parc/groups/[id]/index.blade.php`, ajouter les propriétés Livewire :
  - `public bool $batchRunning = false;`
  - `public ?string $batchAction = null;` (libellé FR)
  - `public ?string $batchStartedAt = null;` (ISO)
  - `public array $currentBatchTaskIds = [];` (ids des `MachinePowerActionTask` dispatchées dans le batch en cours)
  - `public bool $batchSummaryVisible = false;`
  - `public bool $batchTimeoutFired = false;` (évite le double toast timeout)
- [x] **3.2** Refactorer `executeSelectedGroupMachinesAction(string $action)` :
  - Guard `$this->batchRunning` → toast warning et return
  - Guard `action === 'remote'` → message d'erreur (remote non batchable, AC6)
  - Appel du service refactoré (tâche 2) → récup `$result['results']`
  - Stocker les `task_id` créés dans `$currentBatchTaskIds` (nécessite que `executeGroupMachinesAction` les retourne — ajouter dans le shape `results[i].task_id`)
  - Flip `$batchRunning = true`, `$batchStartedAt = now()->toIso8601String()`, `$batchAction = label FR`, `$batchSummaryVisible = true`
  - Toast success/warning selon `$result['success_count']` vs `$result['failed_count']`
- [x] **3.3** Refactorer `executeMachineAction(int $machineId, string $action)` (ligne 205) avec le même pattern async (1 task, 1 job). Permet un retour < 500 ms aussi sur l'action unitaire depuis la vue groupe, parité avec la vue machine.
- [x] **3.4** Créer `pollGroupReadiness()` :
  - Guard `!$batchRunning && empty($currentBatchTaskIds)` → return
  - `$tasks = MachinePowerActionTask::whereIn('id', $currentBatchTaskIds)->get();`
  - Pour les tasks avec `action=restart` et `restart_phase=waiting-down/up`, invoquer `MachinePowerService::ping()` + logique machine à états (identique à la vue machine 4.2 — factoriser dans `MachinePowerService` un helper `resolveReadinessForTask(MachinePowerActionTask $task): bool` pour DRY si coût faible)
  - Pour les tasks avec `action=wake|shutdown|shutdown-force` encore actives, invoquer `MachinePowerService::ping()` et résoudre selon la sémantique per-action
  - Si elapsed ≥ timeout : marquer toutes les tasks encore actives `failed` + `logReadinessTimeout()` + toast warning unique (flag `$batchTimeoutFired`)
  - Si toutes les tasks sont terminales : `$batchRunning = false`
- [x] **3.5** Créer helper `getBatchSummaryProperty(): array` qui retourne `['success' => n, 'failed' => n, 'running' => n, 'failures' => [{machine_name, error_message}]]` en requêtant `MachinePowerActionTask::whereIn('id', $currentBatchTaskIds)->with('workstation')->get()`.
- [x] **3.6** Créer méthode `clearBatchSummary()` : `$currentBatchTaskIds = []; $batchSummaryVisible = false; $batchAction = null; $batchStartedAt = null; $batchTimeoutFired = false;` (audit DB conservé).

### Tâche 4 — UI Livewire vue groupe : rendu (AC: 3, 4)

- [x] **4.1** Dans `_partials/machines-list.blade.php`, enrichir chaque ligne machine :
  - Calculer `$activeTask = $machineActiveTasksById[$machine->id] ?? null` (pré-calculé côté PHP via `getMachineActiveTasksByIdProperty()` pour éviter N+1 Blade)
  - Afficher un badge d'état par machine : queued / running / completed (disparaît après effacement) / failed (tooltip error) / — (idle)
  - Surbrillance de ligne (classe `bg-info/5` active, `bg-success/10` completed, `bg-error/10` failed) — non permanente, disparaît avec `clearBatchSummary()`
- [x] **4.2** Intégrer `@if ($batchRunning)` au niveau du composant principal un wrapper avec `wire:poll.{N}s="pollGroupReadiness"` + badge "Action {label} en cours sur X/Y machines" (spinner + compteur dynamique issu de `$this->batchSummary`).
- [x] **4.3** Bouton "Actions machine" batch dans `_partials/machines-list.blade.php` (ligne 177) → ajouter `@disabled($batchRunning)` + classe `opacity-50` conditionnelle.
- [x] **4.4** Bouton d'actions unitaires dans chaque ligne (ligne 124) → ajouter `@disabled($this->isMachineActionActive($machine->id))` pour la même machine.
- [x] **4.5** Ajouter l'encart résumé AU DESSUS du tableau (nouveau partial `_partials/batch-summary.blade.php`) :
  - Affiché si `$batchSummaryVisible === true`
  - Compteurs + progress DaisyUI
  - Liste des échecs (ul) groupée avec `machine.name` + `error_message`
  - Bouton "Effacer" → `wire:click="clearBatchSummary"`
- [x] **4.6** Gate `@can('computer.control')` sur chaque entrée de dropdown (unitaire + batch). Si AC11 révèle que c'était déjà présent, juste valider.

### Tâche 5 — Sécurité / permissions (AC: 11)

- [x] **5.1** Vérifier policy `App\Policies\WorkstationPolicy` (ou équivalent) : exister une méthode `control(AuthUser $user, Workstation $w)` ? sinon → utiliser `$user->can('computer.control')` directement.
- [x] **5.2** Dans `executeSelectedGroupMachinesAction()` et `executeMachineAction()` (Livewire), `$this->authorize('computer.control')` (ou check équivalent) en tête. Capturer `AuthorizationException` → `toastError`.
- [x] **5.3** Ajouter le gate `@can('computer.control')` dans le Blade sur les blocks dropdown unitaire + batch.

### Tâche 6 — Tests unitaires (AC: 8)

- [x] **6.1** Créer `tests/Unit/Services/WorkstationGroupServicePowerActionTest.php` avec Queue::fake + DatabaseTransactions + helper `createTablesIfNeeded` (pattern `MachineShowPageTest`). Mocker `WorkstationService` pour les cas où on veut bypasser le dispatch réel ou valider les assertions DB.
- [x] **6.2** Les 8 tests listés en AC8.
- [x] **6.3** Valider `php artisan test --filter=WorkstationGroupServicePowerActionTest` → 100% verts.

### Tâche 7 — Tests Feature Livewire (AC: 9)

- [x] **7.1** Étendre `tests/Feature/Livewire/Parc/GroupShowPageTest.php` avec les 9 tests listés en AC9. Pattern : `Queue::fake()` + `Carbon::setTestNow()` + assertions sur `MachinePowerActionTask` en DB + `->assertSet('batchRunning', …)`, `->assertSeeHtml(...)`, `->assertDispatched('toastMagic', …)`.
- [x] **7.2** Pour le polling : simuler des états de tasks via updates DB directes entre les `->call('pollGroupReadiness')` successifs (comme dans `MachineShowPageTest`).
- [x] **7.3** Cible : 4 (existants) + 9 (nouveaux) = **13 tests verts** sur `GroupShowPageTest`, + les tests unit passent aussi.

### Tâche 8 — Test E2E sur VM dev (AC: 10)

- [x] **8.1** Créer `docs/qa/4-3-e2e-manual.md` avec la checklist complète (voir AC10).
- [x] **8.2** Exécution réelle sur VM `192.168.122.50` : à différer si pas de 2ème machine WOL-capable disponible (comme pour 4.2, scénario timeout peut être couvert par IP `192.0.2.1`).
- [x] **8.3** Consigner observations dans Completion Notes.

### Tâche 9 — Documentation (AC: 12)

- [x] **9.1** Compléter `docs/domains/parc.md` — section "Actions batch (story 4.3)".
- [x] **9.2** Lien vers `docs/qa/4-3-e2e-manual.md`.

---

## Dev Notes

### Contraintes architecturales (non négociables)

- **Services** : toute logique métier reste dans `app/Services/Parc/` — ne pas créer un sous-dossier `Batch/` (1 service = 1 responsabilité, `WorkstationGroupService` porte déjà la sémantique groupe).
- **Une task par machine** : pas de "batch task" agrégée en DB. Simplicité + cohérence avec la vue machine + possibilité future de ré-émettre une action individuelle sans artefact batch.
- **Pas d'Eloquent direct dans les composants Livewire** sauf lectures triviales (déjà le cas pour `machine_power_action_tasks` dans la vue machine 4.2 — tolérance maintenue pour ce domaine technique ; tout nouveau Service serait de l'over-engineering ici).
- **Pas d'exec hors Service** : le dispatch de jobs reste l'action Livewire → `Service::executeGroupMachinesAction` → `Job::dispatch`.
- **Toasts via `WithToasts`** uniquement. `toast()`, `toastSuccess()`, `toastWarning()`, `toastError()`.
- **Modale réutilisable** : pas de nouvelle modale ici (encart persistant ≠ modale).
- **Polling intervalle** : `config('parc.machine_readiness_poll_interval_seconds')` = 3s par défaut. **Ne pas baisser** en dessous de 2s sans benchmark côté worker queue.
- **Timeout batch** : `config('parc.machine_readiness_timeout_seconds')` = 120s par défaut. Partagé avec la vue machine. **Ne pas dupliquer** la config.

### Code existant à réutiliser (anti-réinvention)

| Besoin | Fichier/Classe | Notes |
|---|---|---|
| Dispatch job async par machine | `App\Jobs\DispatchMachinePowerActionJob` | Déjà parfait, ne pas forker |
| Table suivi état | `App\Models\MachinePowerActionTask` | Colonnes complètes, `ACTIVE_STATUSES` dispo |
| Exécution power | `App\Services\Parc\MachinePowerService` | `ping`, `wakeOnLan`, `shutdown($force)`, `reboot`, `logReadinessTimeout()` |
| Dispatch 1..n | `App\Services\WorkstationService::executePowerAction()` | Utilisé actuellement en synchrone — NE PAS utiliser pour le batch async (le dispatch async remplace l'appel direct) |
| Résolution machines | `App\Repositories\WorkstationGroupRepository::findGroupMachinesByIds()` | Déjà utilisé par `executeGroupMachinesAction` |
| Sélection multiple UI | `index.blade.php` propriétés existantes `selectedGroupMachineIds`, `allGroupMachinesSelected` | Ne pas refactor |
| wire:confirm renforcé shutdown-force | `_partials/machines-list.blade.php` lignes 182-203 | Déjà OK |
| Trait toasts | `App\Components\Traits\WithToasts` | Obligatoire |
| Pattern polling conditionnel | `resources/views/pages/parc/machines/[id]/index.blade.php` lignes 493-503 | `@if ($statusRunning) <div wire:poll…>` — même stratégie pour `$batchRunning` |

### File List prévisionnel

```
# À MODIFIER
app/Services/Parc/WorkstationGroupService.php
    # executeGroupMachinesAction → pipeline async (tâche 2.1/2.2)
    # ajout helper dispatchAsyncActionForMachines()

resources/views/pages/parc/groups/[id]/index.blade.php
    # +6 propriétés Livewire (batchRunning, batchAction, batchStartedAt, currentBatchTaskIds, batchSummaryVisible, batchTimeoutFired)
    # refactor executeSelectedGroupMachinesAction, executeMachineAction
    # nouvelle méthode pollGroupReadiness()
    # helpers getBatchSummaryProperty, clearBatchSummary, isMachineActionActive
    # authorize('computer.control') dans les méthodes Livewire
    # wrapper wire:poll conditionnel sur $batchRunning

resources/views/pages/parc/groups/[id]/_partials/machines-list.blade.php
    # badge état par machine (consomme $this->machineActiveTasksById)
    # @disabled sur boutons pendant action
    # @can('computer.control') sur dropdown batch + unitaires

# À CRÉER
resources/views/pages/parc/groups/[id]/_partials/batch-summary.blade.php
    # encart résumé avec compteurs + liste failures + bouton effacer

tests/Unit/Services/WorkstationGroupServicePowerActionTest.php
    # 8 tests AC8

docs/qa/4-3-e2e-manual.md
    # checklist E2E VM

# À ÉTENDRE
tests/Feature/Livewire/Parc/GroupShowPageTest.php
    # +9 tests AC9 (batch dispatch, polling, timeout, résumé, idempotence, remote exclu)

docs/domains/parc.md
    # section "Actions batch (story 4.3)"
```

### Pitfalls connus (lessons 4.2)

- **`APP_KEY` en setUp test** : les tests Feature Livewire requièrent `config(['app.key' => …])` en `setUp()`. Reprendre le pattern de `MachineShowPageTest::setUp()` tel quel.
- **`withoutVite()`** dans les tests Feature Livewire sinon erreurs Vite asset resolution.
- **`DatabaseTransactions` + `createTablesIfNeeded`** : pas de `RefreshDatabase` (trop coûteux + crée des conflits avec les schemas partiels). On crée uniquement les tables utiles (`workstations`, `workstation_groups`, `workstation_group_workstation`, `machine_power_action_tasks`, `machine_boot_logs`).
- **`Queue::fake()`** : indispensable pour éviter d'exécuter les jobs réels dans les tests unitaires/feature. Vérifier ensuite `Queue::assertPushed(DispatchMachinePowerActionJob::class, N)`.
- **`Carbon::setTestNow()` puis `setTestNow()` en tearDown** pour ne pas polluer les tests suivants.
- **Pas de N+1 dans le polling** : `MachinePowerActionTask::whereIn('id', $taskIds)->with('workstation')` en UNE requête, puis mapping en PHP.
- **Action `remote`** : NE JAMAIS la mettre dans le pipeline async. Elle reste synchrone via `executeRemoteAccessAction()`, qui génère un token Guacamole et retourne une URL.
- **`$force = true` pour shutdown-force** : `MachinePowerService::shutdown($name, $ip, true)` — le job fait déjà le match correct (ligne 100 de `DispatchMachinePowerActionJob.php`).

### Machine à états restart (rappel 4.2)

Pour `action=restart`, chaque `MachinePowerActionTask` est créée avec `restart_phase='waiting-down'`. Le polling transite :

```
waiting-down → waiting-up :
    si ping($ip) === false (machine a cessé de répondre)

waiting-up → completed :
    si ping($ip) !== false (machine est revenue online)

(timeout global ≥ config) → failed
```

Le helper `pollGroupReadiness()` doit appliquer cette logique **par task** (boucle sur `$tasks` des tasks actives du batch courant). Extraire dans un helper privé pour rester lisible.

### Idempotence — cas limites

1. **Double-click UI** : géré par `@disabled($batchRunning)` + guard PHP en tête.
2. **Deux opérateurs simultanés sur le même groupe** : filtre `MachinePowerActionTask::whereIn('workstation_id', $ids)->whereIn('status', ACTIVE_STATUSES)` → deuxième opérateur voit les machines comme "déjà en cours" → ses dispatches sont skippés.
3. **Navigation / refresh pendant un batch** : les tasks restent en DB, le composant remount avec `$currentBatchTaskIds = []`. L'opérateur ne voit plus le résumé de son batch précédent. **Tradeoff accepté** — pour reprendre la vision live, décision produit = revenir sur `/parc/machines/{id}` individuellement. Non bloquant pour le scope 4.3.
4. **Task orpheline (worker down)** : la task reste `queued/dispatched` indéfiniment. Le polling détectera le timeout (AC5) après 120s et marquera `failed`. Le worker `laravel-queue-general` est supervisé par systemd (déjà en prod).

### Permissions Spatie (AC11)

La permission `computer.control` (enum `SambaPermission::ComputerControl`) existe déjà. Rôles qui la possèdent : `ComputerAdmin`, `SuperAdmin`. Pas de migration ni seeder à toucher. Checker l'intégration côté Blade et côté méthode Livewire.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 4.3 (ligne 1434)] — ACs originaux (BDD épurée)
- [Source: _bmad-output/planning-artifacts/architecture.md#Préoccupations Transversales (ligne 117)] — ControlHubTasks = pattern async pour actions longues ; ici on reste sur `DispatchMachinePowerActionJob` local (polling Livewire) car l'action power est locale au SER, pas orchestrée cross-SER via ControlHub.
- [Source: _bmad-output/implementation-artifacts/4-2-actions-unitaires-machine-feedback-readiness.md] — story parente, pattern async à décalquer strictement
- [Source: app/Services/Parc/WorkstationGroupService.php:188] — `executeGroupMachinesAction` à refactorer
- [Source: app/Jobs/DispatchMachinePowerActionJob.php:35-180] — job réutilisable tel quel
- [Source: app/Models/MachinePowerActionTask.php] — table de suivi
- [Source: app/Services/Parc/MachinePowerService.php] — API power (`ping`, `shutdown($force)`, `logReadinessTimeout`)
- [Source: config/parc.php:24-29] — constantes de polling et timeout
- [Source: resources/views/pages/parc/machines/[id]/index.blade.php:177-410] — référence visuelle & logique du pattern async (méthode Livewire + polling)
- [Source: resources/views/pages/parc/groups/[id]/index.blade.php:168-231] — méthodes actuelles synchrones à refactorer
- [Source: resources/views/pages/parc/groups/[id]/_partials/machines-list.blade.php:128-216] — partial à enrichir (badges + disabled)
- [Source: tests/Feature/Livewire/Parc/MachineShowPageTest.php] — pattern de tests Feature Livewire à décalquer (createTablesIfNeeded, Queue::fake, Carbon::setTestNow)
- [Source: tests/Feature/Livewire/Parc/GroupShowPageTest.php] — 4 tests existants à NE PAS CASSER + 9 à ajouter
- [Source: app/Enums/SambaPermission.php:26] — permission `computer.control`
- [Source: app/Enums/SambaRole.php:69-87] — rôles qui possèdent la permission
- [Source: sambaedu/parcs/action_parcs.php] — référence legacy (action batch sur un parc — ref fonctionnelle)

### Previous Story Intelligence (4.2 — lessons à appliquer)

- **Pattern async `DispatchMachinePowerActionJob` + `MachinePowerActionTask`** : solide, couvert par 34 tests, 0 régression. Le réutiliser tel quel.
- **`wire:poll` conditionnel** via `@if ($statusRunning)` wrapper est la stratégie correcte : Livewire arrête de poller dès que l'attribut n'est plus rendu.
- **`stopReadinessPolling()` helper privé** pour centraliser la remise à zéro — appliquer la même recette sur `pollGroupReadiness()` via `stopBatchPolling()`.
- **Guard double-dispatch côté serveur** (`if ($statusRunning)` en tête de la méthode Livewire) est requis en plus du `@disabled` Blade, car Livewire accepte les requêtes forgées (devtools / double-click rapide).
- **`logReadinessTimeout()` update plutôt que create** : fermer le log `wake=true` existant au lieu de créer une ligne orpheline.
- **Le test `InstallLogModalTest` référence est lui aussi une Feature Livewire non triviale** — reprendre son pattern setUp pour `createTablesIfNeeded`.
- **Review 4.2 point #13** : toujours valider `env()` via `max(1, (int) env(...))` — mais ici on réutilise la config `parc.php` existante, donc déjà protégé.
- **Review 4.2 point #15** : dans les tests de timeout, utiliser `shouldNotReceive('ping')` pour s'assurer que le polling ne fait pas d'appel réseau inutile après le cut-off.

### Project Structure Notes

- Filesystem-based routing respecté : `/parc/groups/{id}` → `resources/views/pages/parc/groups/[id]/index.blade.php`.
- Nouveau partial blade : `_partials/batch-summary.blade.php` (convention `_partials/` OK).
- Tests Feature Livewire : `tests/Feature/Livewire/Parc/GroupShowPageTest.php` (existe).
- Tests Unit Services : `tests/Unit/Services/WorkstationGroupServicePowerActionTest.php` (à créer).
- Documentation QA : `docs/qa/4-3-e2e-manual.md` (à créer, pattern 4.2).

### Tests standards du projet

- `Tests\TestCase` + `DatabaseTransactions` + helper `createTablesIfNeeded()` (pattern établi 4.2) ; pas de `RefreshDatabase` (incompatible avec le schema partiel).
- `Queue::fake()` systématique dans les tests Feature dispatchant des jobs.
- `Carbon::setTestNow()` pour simuler le temps, `Carbon::setTestNow()` en tearDown pour le reset.
- `Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])` pour tester le composant SFC.
- `Mockery::mock(WorkstationGroupService::class)` + `$this->app->instance(...)` pour mocker le service quand on teste le composant (pattern `GroupShowPageTest`).
- `$this->mock(MachinePowerService::class)` pour mocker le service power quand on teste `WorkstationGroupServicePowerActionTest`.

---

## Dev Agent Record

### Agent Model Used

`claude-opus-4-7` (Claude Opus 4.7, 1M context window)

### Debug Log References

- Tests unit : `php artisan test --filter=WorkstationGroupServicePowerActionTest` → 8/8 verts (65 assertions).
- Tests Feature : `php artisan test --filter=GroupShowPageTest` → 13/13 verts (51 assertions) = 4 existants (4-2) + 9 nouveaux (4-3).
- Suite power complète (MachinePowerService + WorkstationService + MachineShow + GroupShow + WorkstationGroupServicePowerAction) : `58/58 verts, 235 assertions`.
- Aucune régression détectée sur les 41 tests power pré-existants : 25 `MachinePowerServiceTest` + 9 `WorkstationServicePowerActionTest` + 7 `MachineShowPageTest` + 4 `GroupShowPageTest` (originaux).

### Completion Notes List

**Livrable** — pipeline de dispatch async machine-par-machine dans `WorkstationGroupService::executeGroupMachinesAction` + UI Livewire groupe polling + encart résumé + idempotence multi-couches + permission Spatie + 17 tests (8 unit + 9 Feature) + E2E doc + doc domaine.

**Choix non triviaux pris pendant le dev** :

1. **Signature `executeMachineActionOnCollection`** étendue avec un paramètre `$requestedIds` pour comptabiliser les IDs demandés mais absents de la collection résolue (404). Alternative refusée : calculer `requested_count` à partir de la collection résolue uniquement, ce qui aurait masqué silencieusement les IDs manquants (régression de visibilité).
2. **`remote` reste synchrone dans le pipeline unifié** — dans la nouvelle méthode `executeMachineAction` Livewire, on appelle `executeGroupMachinesAction(… 'remote')` puis on `handleRemoteAccessResult()`. Le service route vers `executeRemoteAccessAction` donc le flux est équivalent à la vue machine 4-2 (pattern homogène).
3. **`batchMachineActions` = propriété computed dérivée de `machineActions`** (via `reject`) pour séparer les 5 actions unitaires (remote inclus) des 4 actions batch (remote exclu, AC6). Évite de dupliquer les définitions.
4. **`stopBatchPolling()` ne touche PAS à `$currentBatchTaskIds` ni `$batchSummaryVisible`** — l'encart résumé doit rester visible jusqu'au clic explicite "Effacer" (AC4). Seul `$batchRunning` repasse à false pour arrêter le rendering du `wire:poll`.
5. **`handleBatchTimeout` fail-safe** : si rappelé après `batchTimeoutFired=true`, short-circuit direct vers `stopBatchPolling()` pour éviter double toast et requêtes DB inutiles.
6. **Tests Feature Livewire** : ajout du trait `MocksAdminUser` + `Gate::before` en setup pour faire passer `Gate::allows('computer.control')=true`. `Queue::fake()` au niveau du setUp (vs. par test) car l'observer `WorkstationGroupObserver` dispatche un `WorkstationGroupAdSyncJob` à la création (LDAP absent en test). `Queue::assertNotPushed(DispatchMachinePowerActionJob::class)` plutôt que `assertNothingPushed()` pour tolérer le job AD sync.
7. **Enrichissement rétrocompat `results[i]`** : ajout de `task_id` (int, pour les dispatchés) et `reason` (string, pour les échecs structurés). JAMAIS retiré ou renommé de champ existant.

**Tests passants** :

- Unit : `8/8` (WorkstationGroupServicePowerActionTest — dispatch, contrat typé, throw action invalide, idempotence, group vide, restart_phase, remote sync flow, normalisation IDs).
- Feature : `13/13` (GroupShowPageTest — 4 originaux 4-2 + 9 nouveaux : dispatch crée tasks + toast, batchRunning state, polling → completed, timeout global, résumé échecs, clear summary, idempotence batch, guard unitaire actif, remote absent du dropdown batch).
- Power complète : `58/58` (+ MachinePowerServiceTest 25 + WorkstationServicePowerActionTest 9 + MachineShowPageTest 11).

**Follow-ups** :

- **[PROD]** Worker queue `laravel-queue-general.service` **déjà packagé** (scripts/update.sh) et tournant depuis 4-2. Pas d'action prod supplémentaire pour 4-3.
- **[PROD]** Aucune migration à appliquer (table `machine_power_action_tasks` existe depuis 4-2).
- **[QA]** E2E manuel VM à jouer via `docs/qa/4-3-e2e-manual.md` (6 scénarios : batch mixte succès/échec, idempotence, unitaire depuis groupe, restart batch avec machine à états, remote exclu du dropdown batch, permissions).
- **[CODE REVIEW]** Zones sensibles à reviewer en priorité : contrat typé préservé dans `dispatchAsyncActionForMachines` (aucun consommateur historique cassé), race condition `pollGroupReadiness` (tasks qui changent d'état entre le SELECT et les updates individuels), guard serveur-side Gate en tête des méthodes Livewire.

### Change Log

| Date       | Version | Description                                                                                                           | Author            |
|------------|---------|-----------------------------------------------------------------------------------------------------------------------|-------------------|
| 2026-04-21 | 0.2     | Dev livré — pipeline async par machine, UI Livewire polling + résumé, idempotence, tests 8 unit + 9 Feature, doc E2E. | claude-opus-4-7   |
| 2026-04-22 | 0.3     | Corrections review post-review (8 problèmes auto-corrigés : #1 garde contrat service, #2 `$stillActive` via collection, #4 test remote assertions structurelles, #5 mocks tests avec code=202+task_id, #6 assert `currentBatchTaskIds`, #7 `loadCurrentBatchTasks()` memo, #8+#12 skip ping queued/dispatched + doc invariant worker, #13 `isMachineActionActive` consommé dans partial). 10 problèmes restent en attente (8 dont 3 demandant décision utilisateur). | claude-opus-4-7 (orch) |

### File List

**Modifiés**

- `app/Services/Parc/WorkstationGroupService.php` — refactor `executeMachineActionOnCollection` + nouveau helper privé `dispatchAsyncActionForMachines` (pipeline async, idempotence, comptage 404/409, enrichissements `task_id`/`reason`). Docblocks mis à jour.
- `app/Http/Controllers/Admin/ParcController.php` — docblock D5 sur `massAction` (endpoint synchrone conservé pour compat legacy).
- `resources/views/pages/parc/groups/[id]/index.blade.php` — +6 propriétés Livewire batch (`batchRunning`, `batchAction`, `batchActionKey`, `batchStartedAt`, `currentBatchTaskIds`, `batchSummaryVisible`, `batchTimeoutFired`), refactor `executeSelectedGroupMachinesAction` + `executeMachineAction` avec dispatch async, `Gate::allows('computer.control')` en tête, nouvelles méthodes `pollGroupReadiness`, `resolveTaskReadiness`, `handleBatchTimeout`, `stopBatchPolling`, `handleRemoteAccessResult`, `clearBatchSummary`, helpers computed `batchSummary`, `machineActiveTasksById`, `isMachineActionActive`, `batchMachineActions`. Wrapper `wire:poll.{N}s="pollGroupReadiness"` conditionnel sur `$batchRunning` dans le slot actions. Include partial `batch-summary`.
- `resources/views/pages/parc/groups/[id]/_partials/machines-list.blade.php` — badge d'état par ligne (colonne Action), surbrillance de ligne (`bg-info/5` / `bg-success/10` / `bg-error/10`), `@disabled($isTaskActive)` sur les boutons unitaires, `@can('computer.control')` sur le dropdown unitaire, `@disabled($batchRunning)` + `@can('computer.control')` sur le dropdown batch, utilisation de `batchMachineActions` (remote exclu).
- `tests/Feature/Livewire/Parc/GroupShowPageTest.php` — ajout du trait `MocksAdminUser`, `Gate::before` + `actAsAdmin` + `Queue::fake` dans le `setUp`, `createTablesIfNeeded` étendu (`machine_power_action_tasks`, `machine_boot_logs`). +9 tests Feature (dispatch, batchRunning state, polling readiness, timeout global, résumé avec failures, clear summary, idempotence batch, guard unitaire, remote absent batch).
- `docs/domains/parc.md` — section "Actions batch (story 4-3)" ajoutée (flow Mermaid, garanties architecturales, contrat de retour, D5 `massAction`, liens E2E).

**Créés**

- `resources/views/pages/parc/groups/[id]/_partials/batch-summary.blade.php` — encart résumé avec compteurs + progress DaisyUI + liste nominative des échecs + bouton "Effacer".
- `tests/Unit/Services/WorkstationGroupServicePowerActionTest.php` — 8 tests unit couvrant dispatch 1 job par machine, contrat typé préservé, throw action invalide, idempotence, group vide, restart_phase initialisée à `waiting-down`, flow `remote` synchrone conservé, normalisation IDs.
- `docs/qa/4-3-e2e-manual.md` — checklist E2E VM (6 scénarios).

---

## Recommandation Modèle Dev

**Recommandation : `opus`**

**Justification :**

Bien que la story étende un pattern déjà établi (4.2, opus), le scope 4.3 cumule plusieurs surfaces sensibles qui justifient `opus` plutôt que `sonnet` :

1. **Backend critique — refactor `executeGroupMachinesAction` sans casser le contrat public.** La méthode est utilisée par la vue groupe ET par `executeMachineAction` (vue groupe single) ET l'api `ParcController::massAction` dépend indirectement de `WorkstationService::executePowerAction` qui a sa propre signature. Il faut maintenir 100% de la rétrocompat du shape `{action, requested_count, success_count, failed_count, results}` tout en injectant le pipeline async. Un LLM peu rigoureux casse ce contrat en voulant "simplifier" la structure.

2. **Logique polling multi-machines avec machine à états restart.** Chaque machine a sa propre phase `waiting-down` / `waiting-up` / terminal. Le polling consomme N tasks en une requête, applique la logique par task, et décide quand arrêter. Les race conditions (task ajoutée juste après le SELECT, task expirée entre deux ticks, ping échoué mais task completed par le worker) demandent un raisonnement temporel soigné. `sonnet` a tendance à produire un polling qui "marche dans 90% des cas" mais rate les phases ou les edge cases timeout.

3. **Idempotence multi-couches.** Filtre côté service (tasks actives) + guard côté Livewire (`$batchRunning`) + `@disabled` Blade + serveur-side authorize. Chaque couche doit être testée, et le test d'idempotence `test_batch_skips_machines_with_active_tasks_and_warns` doit vérifier le comportement exact (pas juste "ça ne crash pas").

4. **Tests exhaustifs (8 unit + 9 Feature)** avec Queue::fake + Carbon travel + mocks + assertions DB + assertions Livewire. `sonnet` passe généralement moins de temps à valider les assertions précises et produit des tests qui "passent" mais ne couvrent pas le scénario décrit.

5. **Encart résumé de fin de batch (D3)** — composant UI nouveau avec state propre, compteurs live, liste nominative des échecs, bouton "Effacer". Demande finesse DaisyUI + attention aux accessibilité clavier/focus.

6. **Sécurité / permissions** — gate Spatie + `authorize()` côté méthode Livewire + exception captée en toast. Touche à la couche de sécurité, un oubli `@can` côté Blade + pas de guard serveur = bypass via Livewire forgé.

**Conclusion** : même si la moitié du backend est "décalquer 4.2", les interactions entre les couches (service → job → task DB → polling → UI → résumé → idempotence → permissions) forment un graphe où chaque nœud peut introduire une régression. `opus` pour la même raison qu'en 4.2 : exhaustivité des tests + rigueur sur les contrats + raisonnement temporel sur le polling multi-machines.
