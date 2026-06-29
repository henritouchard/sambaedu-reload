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
