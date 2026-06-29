# Story 29.10: Assainissement du tracking `queue_task_runs` (rétention, coût, code mort)

Status: backlog

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

1. **Rétention** — **Given** `queue_task_runs` accumule des lignes `done`/`failed`,
   **When** une purge planifiée s'exécute (commande artisan + planification, ou `prunable`),
   **Then** les lignes terminées plus anciennes qu'un seuil configurable (ex. `config('…retention_days')`, défaut raisonnable type 14 j) sont supprimées, et les runs `running` récents sont préservés.

2. **Coût par job** — **Given** chaque cycle de job passe par les 3 handlers,
   **When** le tracking s'exécute,
   **Then** l'existence de la table est résolue **au plus une fois** (mémoïsation au boot worker / flag) au lieu d'une introspection par événement ; le comportement du dashboard est inchangé.

3. **Flag de coupure** *(optionnel selon arbitrage)* — **Given** un besoin d'exploitation,
   **When** un flag de config (ex. `sambaedu.workers.tracking_enabled`) est à `false`,
   **Then** les handlers ne s'enregistrent pas (coût nul), le dashboard se dégrade proprement (vide), sans erreur.

4. **Code mort** — **Given** `configureRateLimits()` morte,
   **When** on tranche,
   **Then** soit elle est **supprimée** (option par défaut — aucun consommateur), soit **rebranchée** si Henri veut réactiver les limiters ; décision tracée. L'endpoint `discovery` obsolète peut être nettoyé dans la foulée ou laissé selon arbitrage.

5. **Tests** — **Given** la suite HÔTE (php8.4 + sqlite, `RefreshDatabase`),
   **When** elle s'exécute,
   **Then** un test couvre la purge (lignes anciennes supprimées / récentes conservées) et la non-régression du tracking (les 3 tests 29.9 restent verts) ; 0 régression suite complète.

## Tasks / Subtasks

- [ ] **T1 — Rétention** : commande artisan de purge `queue_task_runs` (`status in (done,failed)` + `finished_at`/`failed_at` < seuil) + planification (`routes/console.php` ou `Schedule`), seuil en config. (AC#1)
- [ ] **T2 — Coût par job** : mémoïser la résolution `Schema::hasTable('queue_task_runs')` (une fois) ; évaluer le `SELECT log_lines` préalable (le rendre conditionnel/économe). (AC#2)
- [ ] **T3 — Flag de coupure** (si retenu) : `config('sambaedu.workers.tracking_enabled')` gardant l'enregistrement des handlers dans `boot()`. (AC#3)
- [ ] **T4 — Code mort** : supprimer (défaut) ou rebrancher `configureRateLimits()` selon décision ; nettoyer le TODO `discovery` si arbitré. (AC#4)
- [ ] **T5 — Tests + validation** : test purge + non-régression 29.9 ; suite hôte complète verte. (AC#5)

## Dev Notes

- **Périmètre** : assainissement du canal `queue_task_runs` (rétention/coût) + nettoyage `configureRateLimits`. **PAS** de refonte du dashboard `/workers` ni du `WorkerMonitoringService` (lecture inchangée).
- **Garde-fous projet** : tests HÔTE uniquement (php8.4 + sqlite) ; racine = projet Laravel ; vocabulaire R3 (aucun « central »). Aucune incidence contrat agent / golden / `FROZEN_STATE_HASH` / `ContractV1`.
- **À décider avec Henri** : seuil de rétention exact ; flag de coupure (T3) souhaité ou non ; sort de `configureRateLimits()`/endpoint `discovery`.

## Dépendances

- **Amont** : Story **29.9** (`review`/`done`) — a rétabli `registerQueueTaskTracking()` et tracé cette dette. Code déjà sur `main`.

## References

- [Source: _bmad-output/codeReviews/29-9.md — #3 (rétention), #4 (coût/job), #6 (`configureRateLimits` morte)]
- [Source: app/Providers/AppServiceProvider.php — `registerQueueTaskTracking()` (handlers before/after/failing) + `configureRateLimits()` morte]
- [Source: app/Services/WorkerMonitoringService.php — consommateur `queue_task_runs` (`getDoneTasks`, `limit(100)`, aucune purge)]
- [Source: routes/api.php — TODO suppression `discovery`]

## Recommandation Modèle Dev

**`sonnet`** — assainissement bien cadré (commande de purge + mémoïsation + suppression de code mort), patterns Laravel établis, faible risque, zéro impact contrat/compilé. Arbitrages produit (seuil, flag, sort de `configureRateLimits`) à confirmer avec Henri avant dev.
