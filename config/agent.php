<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Canal agent desired-state (Epic 23)
|--------------------------------------------------------------------------
|
| Story 23.2 — config minimale : seule l'échéance de rotation du token
| agent vit ici. Complété en 23.5 : ttl_seconds, report_history, rétentions.
|
*/

return [

    // Échéance de rotation glissante du token agent (D5, FR13) : au premier
    // check-in passé ce délai, le serveur ré-émet un token via le header
    // X-Agent-New-Token ; l'ancien reste valide jusqu'au premier usage du
    // nouveau (fenêtre de grâce — cf. docs/agent/token-lifecycle.md).
    'token_rotation_days' => (int) env('AGENT_TOKEN_ROTATION_DAYS', 30),

];
