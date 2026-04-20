<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Feedback readiness post-WOL (polling Livewire)
    |--------------------------------------------------------------------------
    |
    | Après une action d'allumage/extinction/redémarrage, l'UI machine Livewire
    | interroge MachinePowerService::ping() toutes les N secondes pour savoir
    | si la machine est effectivement disponible (ou est devenue indisponible).
    | Le polling s'arrête dès que l'état est stabilisé ou que le timeout est
    | atteint (cf. pages/parc/machines/[id]/index.blade.php).
    |
    */

    // Durée maximale d'attente (en secondes) avant de considérer qu'une action
    // (WOL, shutdown, restart) n'a pas abouti côté machine. Passé ce délai,
    // l'UI affiche un toast d'avertissement et arrête le polling.
    // Le max(1, ...) protège contre les env vars invalides qui feraient
    // (int) "abc" = 0 (timeout immédiat) ou une valeur négative.
    'machine_readiness_timeout_seconds' => max(1, (int) env('PARC_MACHINE_READINESS_TIMEOUT_SECONDS', 120)),

    // Intervalle entre deux pings de readiness (en secondes). Utilisé par
    // wire:poll.{interval}s dans la vue Livewire. max(1, ...) protège contre
    // `wire:poll.0s` qui créerait un spam continu côté Livewire.
    'machine_readiness_poll_interval_seconds' => max(1, (int) env('PARC_MACHINE_READINESS_POLL_INTERVAL_SECONDS', 3)),

    /*
    |--------------------------------------------------------------------------
    | Queue async pour les actions power (story 4-2 — correction review #1)
    |--------------------------------------------------------------------------
    |
    | Les actions machine (wake / shutdown / shutdown-force / restart) sont
    | dispatchées via un Job (DispatchMachinePowerActionJob) pour garantir
    | un retour UI < 500 ms (NFR2). Par défaut la connexion `default` est
    | utilisée (= QUEUE_CONNECTION Laravel). Si aucun worker queue n'est
    | lancé et que QUEUE_CONNECTION=sync, le job s'exécute inline —
    | comportement dégradé acceptable en dev, mais l'async devient disponible
    | dès qu'un worker tourne (`php artisan queue:work`).
    |
    */
    'queue_connection' => env('PARC_QUEUE_CONNECTION', 'default'),
];
