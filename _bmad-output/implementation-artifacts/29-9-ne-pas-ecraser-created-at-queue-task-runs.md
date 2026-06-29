# Story 29.9: Ne pas écraser `created_at` de `queue_task_runs` sur retry

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **SE5 (le système)**,
I want **que le tracking d'exécution des jobs (`Queue::before` dans `AppServiceProvider::registerQueueTaskTracking()`) ne pose `created_at` sur la ligne `queue_task_runs` QUE lors d'un INSERT, et jamais lors d'un UPDATE déclenché par un retry / re-dispatch du même `task_uuid`**,
so that **l'horodatage de première apparition d'un run de queue soit préservé (intégrité de l'historique du run) au lieu d'être réécrit à `now()` à chaque nouvelle tentative**.

> **Nature : correctif d'un bug pré-existant, faible sévérité, périmètre minimal. Occurrence SŒUR de la story 29.7 sur une autre table.**
> Origine = follow-up **P3🟡** de la review 29.7 (`_bmad-output/codeReviews/29-7.md`, problème #3 « occurrence sœur »). Le défaut est **PRÉ-EXISTANT** (hérité de la mise en place du tracking `queue_task_runs`), **PAS une régression**. Mécanisme **identique** à 29.7 : `created_at => now()` placé dans le tableau de VALEURS d'un `updateOrInsert(...)`, donc appliqué aussi bien à l'INSERT qu'à l'UPDATE.
> **Le correctif tient en ~5 lignes** : remplacer le tableau de valeurs du `Queue::before` par une **closure** qui pose `created_at` uniquement à l'INSERT. Aucune décision d'architecture, aucune migration, aucun changement de schéma, aucun impact contrat/agent/compilé.

## Contexte du code (constat vérifié 2026-06-29)

### Le bug — `created_at` dans les VALEURS de `updateOrInsert` (handler `Queue::before`)

`app/Providers/AppServiceProvider.php`, méthode `registerQueueTaskTracking()`, handler `Queue::before` (L.260-274) :

```php
DB::table('queue_task_runs')->updateOrInsert(
    ['task_uuid' => $taskUuid],
    [
        'queue' => (string) $event->job->getQueue(),
        'job_name' => $jobName,
        'status' => 'running',
        'started_at' => now(),
        'finished_at' => null,
        'failed_at' => null,
        'error_message' => null,
        'log_lines' => "[" . now()->toDateTimeString() . "] START {$jobName}",
        'updated_at' => now(),
        'created_at' => now(),   // ← dans les VALEURS → appliqué aussi à l'UPDATE
    ],
);
```

`created_at => now()` est dans le **2e argument (les VALEURS)**. Sémantique Laravel : ce 2e argument est appliqué **aussi bien à l'INSERT qu'à l'UPDATE**. Sur un **retry / re-dispatch d'un même `task_uuid`** (la ligne existe déjà), l'UPDATE réécrit `created_at` à `now()` → l'horodatage de **première apparition** du run est **perdu**. (Sur un INSERT, c'est correct : la ligne naît à `now()`.)

### Les handlers `after` / `failing` sont SAINS (ne pas toucher)

`Queue::after` (L.289-299) et `Queue::failing` (L.315-326) appellent aussi `updateOrInsert(['task_uuid' => …], [...])` mais **ne posent PAS `created_at`** dans leurs valeurs → ils ne réécrivent jamais `created_at`. Ils sont hors-scope. **Ne pas y toucher.**

### La signature Laravel qui permet le fix (VÉRIFIÉE en 29.7)

`vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php` (Laravel 12.x) :

```php
public function updateOrInsert(array $attributes, array|callable $values = [])
{
    $exists = $this->where($attributes)->exists();
    if ($values instanceof Closure) {
        $values = $values($exists);   // ← la closure reçoit bool $exists
    }
    if (! $exists) {
        return $this->insert(array_merge($attributes, $values));   // INSERT
    }
    if (empty($values)) {
        return true;
    }
    return (bool) $this->limit(1)->update($values);                // UPDATE
}
```

Le 2e argument **accepte une `Closure`** qui reçoit `bool $exists` et retourne le jeu de valeurs **adapté au cas** (INSERT vs UPDATE). C'est exactement le correctif appliqué en 29.7 (signature confirmée dans `vendor`).

### Pourquoi la sévérité est faible 🟡 (ne pas sur-traiter)

- **N'affecte PAS le compilé / le contrat agent** : `queue_task_runs` est une table de **bookkeeping interne** de l'exécution des jobs ; elle n'entre dans aucun payload desired-state, golden, `FROZEN_STATE_HASH` ni `ContractV1`.
- **Le seul tort** : corruption de l'historique de **première apparition** d'un run rejoué (un run retenté « paraît » créé à la dernière tentative). Défaut d'intégrité de données, pas un défaut fonctionnel visible.
- **Le reset des autres champs sur retry est INTENTIONNEL** (`status→running`, `started_at→now`, `finished_at/failed_at/error_message→null`, `log_lines→START`) : une nouvelle tentative redémarre légitimement le run. **Seul `created_at` doit être préservé.** Ne pas élargir le scope au comportement de reset.

## Acceptance Criteria

1. **Given** une ligne `queue_task_runs` existe déjà pour un `task_uuid` (premier passage du job),
   **When** le même `task_uuid` repasse par `Queue::before` (retry / re-dispatch — chemin UPDATE),
   **Then** la ligne conserve son `created_at` **d'origine inchangé** (pas réécrit à `now()`), tandis que `updated_at`, `started_at`, `status='running'` et le reset de `finished_at`/`failed_at`/`error_message`/`log_lines` restent **appliqués comme aujourd'hui**.

2. **Given** aucune ligne `queue_task_runs` n'existe pour le `task_uuid`,
   **When** le job passe par `Queue::before` (chemin INSERT),
   **Then** la ligne est créée avec `created_at` **ET** `updated_at` posés à `now()`.

3. **Given** les deux chemins ci-dessus,
   **When** le tracking s'effectue,
   **Then** **aucune régression** : les handlers `Queue::after` et `Queue::failing` restent **strictement inchangés** ; tous les autres champs du `before` (queue, job_name, status, started_at, log_lines, updated_at, resets) sont écrits comme avant ; le garde `Schema::hasTable('queue_task_runs')` est conservé.

4. **Given** la suite de tests HÔTE (php8.4 + sqlite, `RefreshDatabase`),
   **When** elle s'exécute,
   **Then** un test couvre **explicitement** le scénario **UPDATE-préserve-`created_at`** (le cœur) : on pré-insère une ligne `queue_task_runs` avec un `created_at` connu/figé dans le passé, on déclenche le handler `Queue::before` pour le même `task_uuid` (ou on simule un second passage), on **asserte** que `created_at` est **identique** à la valeur d'origine et que `updated_at`/`started_at` ont **avancé** ; **et** le cas INSERT (`created_at` posé à la création).

## Tasks / Subtasks

- [x] **T1 — Corriger `Queue::before` : closure `updateOrInsert`** (AC: #1, #2, #3)
  - [x] Dans `app/Providers/AppServiceProvider.php`, `registerQueueTaskTracking()`, remplacer le **tableau de valeurs** du `updateOrInsert` de `Queue::before` (L.260-274) par une **closure** posant `created_at` uniquement à l'INSERT, par ex. :
    ```php
    DB::table('queue_task_runs')->updateOrInsert(
        ['task_uuid' => $taskUuid],
        fn (bool $exists): array => array_merge([
            'queue' => (string) $event->job->getQueue(),
            'job_name' => $jobName,
            'status' => 'running',
            'started_at' => now(),
            'finished_at' => null,
            'failed_at' => null,
            'error_message' => null,
            'log_lines' => "[" . now()->toDateTimeString() . "] START {$jobName}",
            'updated_at' => now(),
        ], $exists ? [] : ['created_at' => now()]),
    );
    ```
  - [x] **Ne pas** modifier les handlers `Queue::after` ni `Queue::failing`, ni le garde `Schema::hasTable(...)`, ni le calcul de `$taskUuid`/`$jobName`. Vérifier que `$event` et `$jobName` sont bien dans la portée capturée par la closure (ils le sont — la closure parente du handler les capture).
  - [x] `php -l` sur `AppServiceProvider.php`.

- [x] **T2 — Test de régression UPDATE-préserve-`created_at` + INSERT-pose-`created_at`** (AC: #4)
  - [x] Créer un test Feature dédié (ex. `tests/Feature/Queue/QueueTaskRunCreatedAtPreservationTest.php`, trait `RefreshDatabase`) :
    - **INSERT** : déclencher `Queue::before` pour un `task_uuid` neuf → la ligne `queue_task_runs` a `created_at` non nul (≈ `now()`).
    - **UPDATE** (cœur) : pré-insérer une ligne avec `created_at` figé dans le **passé** (ex. `now()->subDays(3)`) pour un `task_uuid` donné ; déclencher un second passage `Queue::before` pour le même `task_uuid` ; **asserter** `created_at` **strictement inchangé** (égal à la valeur figée) **et** `started_at`/`updated_at` **postérieurs**, `status='running'`.
  - [x] Pour déclencher le handler : soit dispatcher un vrai job sur la connexion `sync` et inspecter la table, soit invoquer le `JobProcessing` via un faux `Job` (`Illuminate\Contracts\Queue\Job`) avec un `payload()` portant `uuid`/`displayName`. Choisir l'approche la plus lisible et stable sur HÔTE sqlite. SQLite n'applique pas les types datetime PG → comparer le **contenu** des colonnes, pas des bornes.

- [x] **T3 — Runbook QA (footprint minimal — décision dev-agent)** (AC: #1, #2)
  - [x] L'intégrité de `created_at` sur une table de **bookkeeping interne** n'est **pas un scénario manuellement testable** à valeur ajoutée (pas de surface utilisateur ; la garde pertinente est le test automatisé T2). **Ne PAS créer de fichier de domaine dédié pour ce fix.**
  - [x] Si — et seulement si — `queue_task_runs` est déjà surfacé dans un runbook de domaine existant (dashboard de suivi des tâches/queue), y **appender** une courte note de non-régression « retry préserve `created_at` du run » en mode append-only (numérotation stable). Sinon, **ne rien ajouter** et le justifier dans les Completion Notes.

- [x] **T4 — Validation finale** (AC: #1–#4)
  - [x] `php artisan test --filter "QueueTaskRun"` (ou le filtre du test créé) sur HÔTE → vert.
  - [x] Confirmer **0 régression** : handlers `after`/`failing` intacts, garde `hasTable` intact, tous les autres champs du `before` écrits comme avant.
  - [x] Vérifier qu'**aucun** fichier contrat agent / golden / `FROZEN_STATE_HASH` / `ContractV1` / migration n'est touché (`git status` : seuls `AppServiceProvider.php` et le test changent ; éventuelle note QA).

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 29.9

**DANS** :
- Correction **chirurgicale** du handler `Queue::before` : closure `updateOrInsert` posant `created_at` uniquement à l'INSERT.
- **1 test** de régression (UPDATE préserve `created_at` ; INSERT le pose).

**HORS** (ne pas déborder) :
- **Toucher `Queue::after` / `Queue::failing`** : ils ne posent pas `created_at` — non concernés.
- **Modifier le comportement de reset** des champs sur retry (status/started_at/finished_at/log_lines) — intentionnel, inchangé.
- **Toute migration / changement de schéma** sur `queue_task_runs` — aucun.
- **Toute modification du contrat agent / golden / `FROZEN_STATE_HASH` / `ContractV1` / `StateCompiler`** — `queue_task_runs` n'entre pas dans le compilé.

### Décisions de conception

- **Closure plutôt que double appel** : voie idiomatique Laravel 12 (`updateOrInsert(array, callable)`), la plus simple et atomique pour différencier valeurs d'INSERT vs d'UPDATE. Strictement le même patron que le fix 29.7. [Source: vendor Builder.php ; 29-7]
- **`array_merge` du socle commun + `created_at` conditionnel** : garde le jeu de champs de reset (intentionnel) identique entre INSERT et UPDATE, n'ajoutant `created_at` que sur INSERT. Lisible et minimal.
- **Test : figer `created_at` dans le passé** avant le second passage est la seule façon de **prouver** la non-réécriture (sinon `now()` ≈ `now()` masquerait le bug — exactement l'angle mort relevé en 29.5/29.7).

### Garde-fous projet

- **Tests HÔTE uniquement** : php8.4 + `pdo_sqlite`, `RefreshDatabase`, **jamais la VM**. [Source: mémoires projet — phpunit_test_env_host_vs_vm, worktree_no_vm_sync]
- **Racine = projet Laravel** (pas de préfixe `laravel/`). [Source: mémoire projet — root_is_laravel]
- **VM** : 29.9 n'ajoute **aucune** migration → rien à jouer côté VM. [Source: mémoire projet — vm_migrations_not_auto_applied]
- **Vocabulaire R3** : aucun « central » (sans objet ici). [Source: prd-contrat-manage-se5.md#R3]

### Patrons de référence

- **Story 29.7** (`_bmad-output/implementation-artifacts/29-7-ne-pas-ecraser-created-at-pivot-capability-assignments.md`) : MÊME fix sur `capability_assignments`. Calquer la closure et l'approche de test (figer `created_at` dans le passé, asserter l'invariance).
- **Closure `updateOrInsert`** : `vendor/.../Query/Builder.php::updateOrInsert(array $attributes, array|callable $values)` — la closure reçoit `bool $exists`.

### Project Structure Notes

- **Modifiés** :
  - `app/Providers/AppServiceProvider.php` (closure `updateOrInsert` dans le handler `Queue::before` de `registerQueueTaskTracking()` — ~5 lignes).
- **Nouveau** :
  - `tests/Feature/Queue/QueueTaskRunCreatedAtPreservationTest.php` (test UPDATE-préserve-`created_at` + INSERT).
- **Inchangés (à NE PAS toucher)** : handlers `Queue::after` / `Queue::failing`, garde `Schema::hasTable`, migration `queue_task_runs`, contrat agent / golden / `FROZEN_STATE_HASH` / `ContractV1`, `StateCompiler`.

### References

- [Source: _bmad-output/codeReviews/29-7.md — problème #3 (occurrence sœur `queue_task_runs`, P3)] — origine du follow-up, tracé backlog 29-9.
- [Source: app/Providers/AppServiceProvider.php L.249-328] — `registerQueueTaskTracking()` : `Queue::before` (bug L.260-274), `after` (L.277-300, sain), `failing` (L.302-327, sain).
- [Source: _bmad-output/implementation-artifacts/29-7-ne-pas-ecraser-created-at-pivot-capability-assignments.md] — story sœur (même fix, même nature, mêmes pièges de test).
- [Source: vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php] — `updateOrInsert(array $attributes, array|callable $values)` : closure reçoit `bool $exists`.
- [Source: mémoires projet — phpunit_test_env_host_vs_vm, worktree_no_vm_sync, root_is_laravel, vm_migrations_not_auto_applied].

## Dépendances

- **Amont** :
  - **Story 29.7** (`done`) : story sœur établissant le patron de fix (closure `updateOrInsert`) et l'approche de test. Dépendance de **patron**, pas de code partagé.
- Aucune autre dépendance. Le code fautif (`registerQueueTaskTracking`) est déjà sur `main`.

## Testing

- **Cible d'exécution : HÔTE** (php8.4 + `pdo_sqlite`), `DB_CONNECTION=sqlite`, trait `RefreshDatabase`. **Jamais la VM.**
- Filtre ciblé : `php artisan test --filter "QueueTaskRun"`.
- Couverture obligatoire :
  - **UPDATE préserve `created_at`** (cœur, AC#4) : `created_at` figé dans le passé → inchangé après second passage `Queue::before` ; `started_at`/`updated_at` avancent.
  - **INSERT pose `created_at`** : ligne neuve → `created_at` ≈ `now()`.
  - **Non-régression** : handlers `after`/`failing` inchangés (statut `done`/`failed`, append log).
- **Pièges** : SQLite ne contraint pas les types datetime PG → comparer le **contenu** ; **figer** le `created_at` initial dans le passé (sinon `now()`≈`now()` masque le bug).

## Recommandation Modèle Dev

**`sonnet`.**

Justification : story **volumétriquement et conceptuellement minimale** — 1 fichier applicatif modifié de ~5 lignes (substitution d'un tableau par une closure, strictement iso-29.7), 1 test focalisé. Solution **connue, idiomatique et déjà appliquée/vérifiée en 29.7** (closure `updateOrInsert`), **zéro décision d'architecture**, zéro migration, zéro impact contrat/compilé. Le seul point de vigilance — **figer `created_at` dans le passé** pour prouver la non-réécriture, et **déclencher proprement le handler `Queue::before`** dans le test — est explicité dans les AC/Dev Notes. La review sera routée vers le modèle **opposé (opus)**, cohérent avec l'historique 29.x où opus a détecté ce défaut.

## Dev Agent Record

### Agent Model Used

**sonnet (claude-sonnet-4-6)**

### Debug Log References

Découverte lors de l'implémentation : `registerQueueTaskTracking()` n'était plus appelée depuis `boot()`. **RECTIFICATION post-review (archéologie git)** : ce n'était PAS un « oubli » — l'appel était présent en `boot()` au commit `8d78d51` (« first independant commit ») puis **retiré délibérément/collatéralement au commit `997df15`** (« fix livewire update redirection », 2026-03-13, henritouchard), en même temps que `configureRateLimits()`. Conséquence : le tracking a été actif puis coupé ~3,5 mois, **le dashboard `/workers`** (`WorkerMonitoringService` → `queue_task_runs`) n'enregistrait plus rien. **Décision Henri (post-review) : rétablir l'activation** (vraie feature restaurée), commentaire `boot()` rendu véridique. La dette connue de ce canal (rétention/purge — croissance non bornée ; coût DB par job) est **notée dans le code et déférée à une story d'assainissement dédiée** (hors-scope 29.9).

### Completion Notes List

- T1 : closure `fn(bool $exists): array => array_merge([...champs reset...], $exists ? [] : ['created_at' => now()])` appliquée dans le handler `Queue::before`. Appel `$this->registerQueueTaskTracking()` **rétabli** dans `boot()` (cf. Debug Log — retiré en `997df15`, restauré sur décision Henri) avec commentaire véridique + note de dette. `php -l` vert.
- **Post-review (corrections orchestrateur opus)** : (#7) les handlers `Queue::after` et `Queue::failing` reçoivent la **même closure iso-`before`** → en cas d'INSERT par ces handlers seuls (course rare), `created_at` est posé au lieu de rester NULL ; (#1) commentaire `boot()` + Debug Log rectifiés (narration « jamais appelée » corrigée) ; (#8) test fiabilisé (cf. T2). Garde `Schema::hasTable`, calcul `$taskUuid`/`$jobName` : inchangés.
- T2 : test `tests/Feature/Queue/QueueTaskRunCreatedAtPreservationTest.php` créé (RefreshDatabase, Mockery). **3 méthodes** (post-review) : `inserting_a_new_queue_task_run_sets_created_at` (AC INSERT — renforcé #8 : `created_at` ≈ `now()` et = `updated_at`) + `re_dispatching_a_task_uuid_preserves_original_created_at` (AC UPDATE cœur — `created_at` figé à `now()->subDays(3)`, prouvé INCHANGÉ ; `updated_at`/`started_at` avancés ; comparaisons via `Carbon::parse`→`equalTo`/`greaterThan`, robuste cross-driver #8) + `after_handler_inserting_a_fresh_run_sets_created_at` (#7 — `after` seul pose `created_at`). Approche : `Mockery::mock(Job::class)` + `event(new JobProcessing/JobProcessed('sync', $job))`.
- T3 : `queue_task_runs` n'est surfacé dans aucun runbook QA (`docs/qa/`). Table de bookkeeping interne uniquement. Aucune note QA ajoutée, conformément aux instructions T3.
- T4 : `php artisan test --filter "QueueTaskRun"` → **3 tests verts, 15 assertions** (après corrections post-review). Non-régression élargie initiale (`--filter "InstallApplicationJob|MachinePower|WorkstationGroup|Queue"`) : 162 tests passés, 0 rouge. **Validation #5 (orchestrateur)** : suite **hôte COMPLÈTE** → **4715 passed, 3 failed, 141 skipped** (25197 assertions, ~480 s). Les 3 échecs (`WorkstationEnrollmentServiceTest` × 2 + `IpxeEnrollmentRoomEndpointTest`, tous sur `assignRoom()`→`false`) sont **PRÉEXISTANTS** : reproduits à l'identique sur `main` propre (`git stash` du diff) → **indépendants de 29.9, 0 régression introduite**. (Bug `assignRoom()` à traiter en story séparée.) `git status` : `AppServiceProvider.php` + le test = nos changements code ; 0 contrat/golden/migration touché.

### File List

- `app/Providers/AppServiceProvider.php` (T1 — closure `updateOrInsert` sur `Queue::before` + rétablissement de l'appel dans `boot()` (commentaire véridique) ; post-review : même closure sur `after`/`failing` (#7))
- `tests/Feature/Queue/QueueTaskRunCreatedAtPreservationTest.php` (T2 — 3 tests : AC INSERT renforcé + AC UPDATE-préserve-`created_at` (Carbon) + after-INSERT pose created_at (#7))
