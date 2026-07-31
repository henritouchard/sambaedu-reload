<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Story 29.10 — Purge quotidienne du tracking queue_task_runs
|--------------------------------------------------------------------------
| Supprime les runs done > retention.done_days (14 j) et failed >
| retention.failed_days (30 j). Les runs running sont toujours préservés.
| Seuils configurables dans config/sambaedu.php (workers.retention.*).
*/
// Slot 02:40 : échelonnement de la fenêtre de maintenance (discipline Story 24.1
// — trash 02:00, federated 02:30, agent:reports:prune 02:35) pour ne pas empiler
// les purges sur le créneau minuit (error-logs:prune, parc:prune-group à 00:00).
Schedule::command('queue-task-runs:prune')
    ->dailyAt('02:40')
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Story 56.1 — Synchro quotidienne des sources d'extensions distantes
|--------------------------------------------------------------------------
| Récupère et VÉRIFIE (signature Ed25519, avant tout décodage) le catalogue de
| chaque source distante active. Même moteur que le bouton « Actualiser » de
| /admin/extensions/sources (doctrine AR1 : un seul chemin de synchro).
|
| NFR7 — son échec n'affecte ni le core ni les tuiles : un dépôt injoignable
| laisse en place le dernier catalogue VÉRIFIÉ (le registre EST le cache
| local), un catalogue refusé masque les extensions non installées de cette
| source sans jamais toucher aux extensions intégrées. Aucun chemin d'échec ne
| supprime quoi que ce soit.
|
| Slot 02:50 : discipline d'échelonnement de la fenêtre de maintenance
| (02:00 trash, 02:30 federated, 02:35 agent:reports:prune, 02:40
| queue-task-runs:prune).
*/
Schedule::command('ext:sources:sync')
    ->dailyAt('02:50')
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Story 56.5 — Sonde de SANTÉ des extensions `app` installées
|--------------------------------------------------------------------------
| Sonde `http://127.0.0.1:<installed_port>/` pour chaque `app` installée et
| PERSISTE l'état observé sur `extensions.health_*`.
|
| NFR9 — c'est ICI qu'on mesure, et NULLE PART AILLEURS au fil de l'eau : la
| navbar (rendue sur TOUTE page authentifiée), la bibliothèque et la fiche
| LISENT l'état persisté. Une sonde au rendu coûterait une requête HTTP par
| tuile et par page vue.
|
| Période de 5 minutes : elle est DÉRIVÉE par
| `config('extensions.health.stale_after')` (900 s = 3 passages tolérés).
| Changer la période ici, c'est changer ce seuil là-bas — les deux réglages
| sont liés et l'énoncé de la dérivation vit dans la config (leçon review
| 56.3 #2).
|
| NFR6/NFR7 — son échec n'affecte rien : une sonde qui échoue écrit
| `unreachable` (un fait), n'audite rien, ne redémarre rien et ne supprime
| rien. Le doctor porte le verdict, l'admin décide.
*/
Schedule::command('ext:health:check')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
