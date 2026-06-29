# Story 29.10: Assainissement du tracking `queue_task_runs` (rétention, coût, code mort)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **SE5 (le système)**,
I want **que le canal de tracking d'exécution des jobs (`queue_task_runs`, réactivé en 29.9) soit borné en rétention et en coût, et que le code mort attenant soit nettoyé**,
so that **le dashboard `/workers` reste exploitable durablement sans faire croître la base indéfiniment ni alourdir chaque job traité**.

> **Nature : dette technique tracée lors de la review 29.9** (`_bmad-output/codeReviews/29-9.md` #3/#4/#6). Le tracking `registerQueueTaskTracking()` a été **rétabli en 29.9** (il alimente le dashboard `/workers` via `WorkerMonitoringService`), avec des défauts connus volontairement déférés ici. Story d'assainissement, **pas une nouvelle feature** : le comportement observable du dashboard ne change pas, on borne ses effets de bord.

## Contexte du code (constat 2026-06-29)

### #3 — Croissance non bornée de `queue_task_runs`

`AppServiceProvider::registerQueueTaskTracking()` fait, sur chaque job, un `updateOrInsert` clé sur `task_uuid` (1 ligne par job, statut `running`→`done`/`failed`). **Aucune purge** : le consommateur `WorkerMonitoringService::getDoneTasks()` lit `->limit(100)` mais ne supprime jamais. Sur un parc qui déclenche des jobs en continu (sync AD, WPKG, installs, overlay), la table grossit **sans plafond**.

### #4 — Coût DB par job

Chaque job déclenche `Schema::hasTable('queue_task_runs')` dans **`before`, `after` ET `failing`** (introspection `information_schema` sur PG), plus un `SELECT log_lines` **avant** l'`updateOrInsert` dans `after`/`failing` → ≥ 4 allers-retours DB/job en sus du métier. Jamais profilé (le canal a dormi ~3,5 mois entre `997df15` et 29.9).

### #6 — Code mort attenant : `configureRateLimits()`

`AppServiceProvider::configureRateLimits()` (rate limiters `discovery` 10/min, `se4fs-api` 100/min) a été retirée de `boot()` au même commit `997df15` et **n'est plus appelée**. **Aucune route** n'utilise ces limiters (`throttle:discovery`/`throttle:se4fs-api` = 0 occurrence) ; l'endpoint `discovery` est lui-même en voie de suppression (TODO `routes/api.php`). Méthode morte à trancher.

## Acceptance Criteria

1. **Rétention (configurable par statut — décision Henri)** — **Given** `queue_task_runs` accumule des lignes `done`/`failed`,
   **When** une purge planifiée s'exécute (commande artisan + planification),
   **Then** les lignes terminées plus anciennes que leur seuil de rétention sont supprimées, avec des **délais configurables et distincts par statut** : `config('sambaedu.workers.retention.done_days')` (défaut **14**) et `config('sambaedu.workers.retention.failed_days')` (défaut **30** — les échecs sont conservés plus longtemps pour diagnostic). Les runs `running` (non terminés) sont **toujours préservés** quel que soit leur âge.

2. **Coût par job** — **Given** chaque cycle de job passe par les 3 handlers,
   **When** le tracking s'exécute,
   **Then** l'existence de la table est résolue **au plus une fois** (mémoïsation au boot worker / flag) au lieu d'une introspection par événement ; le comportement du dashboard est inchangé.

3. **Flag de coupure — NON RETENU (décision Henri)** : pas de `tracking_enabled`. Le tracking reste **toujours actif** (le coût est traité par AC#2 via la mémoïsation `hasTable`). Ne pas ajouter de flag.

4. **Code mort — SUPPRESSION (décision Henri)** — **Given** `configureRateLimits()` morte (aucun consommateur : `throttle:discovery`/`throttle:se4fs-api` = 0 occurrence),
   **When** on assainit,
   **Then** la méthode `configureRateLimits()` est **supprimée** d'`AppServiceProvider`, et le TODO `discovery` obsolète (`routes/api.php`) est nettoyé. **Ne PAS** rebrancher les limiters.

5. **Tests** — **Given** la suite HÔTE (php8.4 + sqlite, `RefreshDatabase`),
   **When** elle s'exécute,
   **Then** un test couvre la purge (lignes anciennes supprimées / récentes conservées) et la non-régression du tracking (les 3 tests 29.9 restent verts) ; 0 régression suite complète.

## Tasks / Subtasks

- [x] **T1 — Rétention configurable par statut** : commande artisan de purge `queue_task_runs` supprimant les `done` plus vieux que `retention.done_days` (14) **et** les `failed` plus vieux que `retention.failed_days` (30), via `finished_at`/`failed_at` ; **jamais** les `running`. Seuils dans `config/sambaedu.php` (clé `workers.retention.{done,failed}_days`). Planification dans `routes/console.php` (`Schedule::command(...)->daily()`). (AC#1)
- [x] **T2 — Coût par job** : mémoïser la résolution `Schema::hasTable('queue_task_runs')` (résolue une fois, pas par événement) ; rendre le `SELECT log_lines` préalable (after/failing) plus économe si possible, sans changer le comportement du dashboard. (AC#2)
- [x] **T3 — Suppression `configureRateLimits()`** : retirer la méthode morte d'`AppServiceProvider` ; nettoyer le TODO `discovery` obsolète (`routes/api.php`). NE PAS rebrancher de limiter. (AC#4)
- [x] **T4 — Tests + validation** : test de purge (done>14j et failed>30j supprimés ; running et récents conservés ; seuils config respectés) + non-régression 29.9 (les 3 tests `QueueTaskRun*` restent verts) ; suite hôte complète verte. (AC#5)

## Dev Notes

- **Périmètre** : assainissement du canal `queue_task_runs` (rétention/coût) + nettoyage `configureRateLimits`. **PAS** de refonte du dashboard `/workers` ni du `WorkerMonitoringService` (lecture inchangée).
- **Garde-fous projet** : tests HÔTE uniquement (php8.4 + sqlite) ; racine = projet Laravel ; vocabulaire R3 (aucun « central »). Aucune incidence contrat agent / golden / `FROZEN_STATE_HASH` / `ContractV1`.
- **Arbitrages tranchés (Henri, 2026-06-29)** : (1) rétention **configurable par statut** — `done` 14 j / `failed` 30 j par défaut, clés `config('sambaedu.workers.retention.{done,failed}_days')` ; (2) **pas** de flag de coupure (tracking toujours actif, coût géré par mémoïsation) ; (3) **supprimer** `configureRateLimits()` + nettoyer le TODO `discovery`.
- **Status `running` préservé** : ne jamais purger un run non terminé (pas de `finished_at`/`failed_at`), même ancien (worker bloqué = info utile).

## Dépendances

- **Amont** : Story **29.9** (`review`/`done`) — a rétabli `registerQueueTaskTracking()` et tracé cette dette. Code déjà sur `main`.

## References

- [Source: _bmad-output/codeReviews/29-9.md — #3 (rétention), #4 (coût/job), #6 (`configureRateLimits` morte)]
- [Source: app/Providers/AppServiceProvider.php — `registerQueueTaskTracking()` (handlers before/after/failing) + `configureRateLimits()` morte]
- [Source: app/Services/WorkerMonitoringService.php — consommateur `queue_task_runs` (`getDoneTasks`, `limit(100)`, aucune purge)]
- [Source: routes/api.php — TODO suppression `discovery`]

## Recommandation Modèle Dev

**`sonnet`** — assainissement bien cadré (commande de purge + mémoïsation + suppression de code mort), patterns Laravel établis, faible risque, zéro impact contrat/compilé. Arbitrages produit (seuil, flag, sort de `configureRateLimits`) à confirmer avec Henri avant dev.

## Dev Agent Record

**Agent Model Used**: claude-sonnet-4-6

**Date Completed**: 2026-06-29

**Completion Notes**:
- T1 : commande `queue-task-runs:prune` créée (`app/Console/Commands/PruneQueueTaskRunsCommand.php`), iso-pattern `PruneAgentReportsCommand`. Clés `workers.retention.{done_days,failed_days}` ajoutées à `config/sambaedu.php`. Planification dans `routes/console.php` (`Schedule::command(...)->daily()->withoutOverlapping()->runInBackground()`).
- T2 : `Schema::hasTable` mémoïsé via une closure `$checkTable` capturée par référence (`&$tableExists`) partagée entre les 3 handlers. La résolution se fait au plus une fois (au premier événement). Le `SELECT log_lines` (after/failing) est conservé tel quel : déjà économe (`->value('log_lines')`), la story le qualifie de "si possible".
- T3 : méthode `configureRateLimits()` supprimée d'`AppServiceProvider` (aucun appel dans `boot()`). TODO `discovery` nettoyé dans `routes/api.php` (commentaire de section mis à jour). Aucun limiter rebranché.
- T4 : 4 tests (`PruneQueueTaskRunsCommandTest`) + non-régression des 3 tests 29.9 (`QueueTaskRunCreatedAtPreservationTest`) — 7/7 verts, 29 assertions.
- Aucun fichier Epic 34 / agent / drives touché.

**Corrections post-review (orchestrateur opus, APPROVE WITH CHANGES — codeReviews/29-10.md)** :
- #1 🟡 : mémoïsation revue → mémoïse UNIQUEMENT le `true` (`if (! $tableExists)`). Steady-state coût/job=0 (AC#2) ET fenêtre greenfield préservée (table apparue mid-life captée) → les commentaires INSERT de `after`/`failing` redeviennent valides. Commentaire d'en-tête réécrit.
- #2 🟡 : planification `->daily()` (00:00, empilait error-logs:prune) → `->dailyAt('02:40')` (discipline d'échelonnement 24.1, fenêtre maintenance 02:xx). Vérifié `schedule:list`.
- #4 🟡 : 2 env vars ajoutées à `.env.example` (`SAMBAEDU_WORKERS_RETENTION_{DONE,FAILED}_DAYS`). Runbook /vm : `config:cache` + chown www-admin après modif config.
- #3 🟡 (accepté) : DELETE non chunké = parité stricte `PruneAgentReportsCommand` ; volume borné (accumulation depuis merge 29.9). #5 🟡 (accepté) : invariant `done`⇒`finished_at`/`failed`⇒`failed_at` garanti par les handlers (anomalie = corruption, hors flux).
- Tests post-corrections : 7/7 ciblés verts ; **suite hôte COMPLÈTE : 4721 passed, 0 failed** (25219 assertions) → 0 régression (le changement de mémoïsation `boot()` n'impacte aucun test).

**Files Created/Modified**:
- `app/Console/Commands/PruneQueueTaskRunsCommand.php` — créé
- `tests/Feature/Queue/PruneQueueTaskRunsCommandTest.php` — créé
- `app/Providers/AppServiceProvider.php` — mémoïsation hasTable + suppression configureRateLimits()
- `config/sambaedu.php` — ajout section `workers.retention`
- `routes/console.php` — ajout `Schedule::command('queue-task-runs:prune')->daily()`
- `routes/api.php` — nettoyage TODO discovery
- `_bmad-output/implementation-artifacts/29-10-assainissement-tracking-queue-task-runs.md` — tâches cochées + Dev Agent Record + Status review
